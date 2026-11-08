"""Reopen synthetic pipeline exports using the existing verification runtime."""

from datetime import date, datetime
from decimal import Decimal
import json
from pathlib import Path
import sys

from openpyxl import load_workbook
from pypdf import PdfReader
import pdfplumber


def verify(directory):
    results=[]
    for source in sorted(directory.glob("*.json")):
        result=json.loads(source.read_text(encoding="utf-8"))
        if result.get("version")!="assocmap.reports.v1":
            continue
        workbook=load_workbook(source.with_suffix(".xlsx"),data_only=False,keep_links=False)
        try:
            expected_sheets=3+len(result["sections"])
            assert len(workbook.worksheets)==expected_sheets, "Unexpected workbook sheet count."
            for index,section in enumerate(result["sections"],1):
                sheet=workbook[f"{index:02d} {section['key'].replace('_',' ')}"[:31]]
                assert sheet.max_row==1+len(section["rows"]), "Workbook lost or duplicated detail rows."
                assert [cell.value for cell in sheet[1]]==[column["label"] for column in section["columns"]]
                assert sheet.freeze_panes=="A2" and sheet.auto_filter.ref==sheet.dimensions
                for row_index,row in enumerate(section["rows"],2):
                    for column_index,column in enumerate(section["columns"],1):
                        cell=sheet.cell(row_index,column_index)
                        expected=row[column["key"]]
                        if expected is None:
                            assert cell.value is None, "Unobserved cell became a recorded value."
                        elif column["type"]=="decimal":
                            assert cell.data_type=="n" and Decimal(str(cell.value))==Decimal(expected)
                        elif column["type"]=="date":
                            assert isinstance(cell.value,(date,datetime))
                            assert (cell.value.date() if isinstance(cell.value,datetime) else cell.value).isoformat()==expected
                        else:
                            assert cell.value==expected
                            if column["type"]=="text":
                                assert cell.data_type=="s", "Text was interpreted as a formula."
                            elif column["key"] in ("year","program_year","quarter","month"):
                                assert cell.number_format=="0", "Reporting periods use a grouped count format."
            summary=workbook["Summary"]
            assert workbook.sheetnames[0]=="Summary", "The report summary must be the first worksheet."
            measures=[(section,item) for section in result["sections"] for item in section["measures"]]
            header=next(row[0].row for row in summary if row[0].value=="Section" and row[1].value=="Measure")
            assert summary.max_row==max(7,len(measures)+header)
            assert summary.freeze_panes==f"A{header+1}"
            assert result["title"] in summary.cell(1,1).value
            for index,(section,item) in enumerate(measures,header+1):
                assert summary.cell(index,1).value==section["title"] and summary.cell(index,2).value==item["label"]
                value=item["value"]
                if isinstance(value,str): assert Decimal(str(summary.cell(index,4).value))==Decimal(value)
                elif value is None: assert summary.cell(index,3).value is None and summary.cell(index,4).value is None
                else: assert summary.cell(index,3).value==value
            assert not any(cell.data_type=="f" for sheet in workbook for row in sheet for cell in row), "Unexpected executable Excel formula."
            for sheet in workbook:
                assert not sheet.sheet_view.showGridLines
                assert sheet.print_title_rows and sheet.page_setup.fitToWidth==1 and sheet.page_setup.fitToHeight==0
            results.append({"test":source.stem+" XLSX canonical cells, summary, types and formula safety","status":"PASS","sheets":len(workbook.worksheets)})
        finally:
            workbook.close()
        pdf=source.with_suffix(".pdf")
        reader=PdfReader(pdf,strict=True)
        assert not reader.is_encrypted and len(reader.pages)>0
        text="\n".join(page.extract_text() or "" for page in reader.pages)
        normalized=" ".join(text.split())
        compact="".join(text.split())
        assert result["title"] in normalized and "Asia/Manila" in normalized
        assert "PHP (Philippine peso)" in normalized
        for section in result["sections"]:
            assert " ".join(section["title"].split()) in normalized, "PDF section missing."
            for item in section["measures"]:
                assert "".join(item["label"].split()) in compact, "PDF indicator missing."
                value=item["value"]
                expected="Unobserved" if value is None else format(Decimal(value),",.2f") if isinstance(value,str) else format(value,",")
                assert expected in text, "PDF indicator value missing."
            for row in section["rows"]:
                for column in section["columns"]:
                    value=row[column["key"]]
                    expected="Unobserved / not applicable" if value is None else format(Decimal(value),",.2f") if column["type"]=="decimal" else \
                        format(value,",") if column["type"]=="integer" and column["key"] not in ("year","program_year","quarter","month") else str(value)
                    assert "".join(expected.split()) in compact, "PDF canonical detail value missing."
        for value in [*result["meta"]["scope"], *map(str,result["meta"]["filters"].values()),result["meta"]["generated_at"],result["meta"]["scope_basis"]]:
            assert "".join(value.split()) in compact, "PDF scope, filter or generation context missing."
        if any("Peñafrancia" in name for name in result["meta"]["scope"]): assert "Peñafrancia" in normalized
        with pdfplumber.open(pdf) as document:
            for page in document.pages:
                assert all(char["x0"]>=-0.5 and char["x1"]<=page.width+0.5 and char["top"]>=-0.5
                           and char["bottom"]<=page.height+0.5 for char in page.chars), "PDF text lies outside the page."
        results.append({"test":source.stem+" PDF reopen, Unicode, headings and page bounds","status":"PASS","pages":len(reader.pages)})
    if not results: raise RuntimeError("No synthetic exports were found.")
    return results


if __name__=="__main__":
    print(json.dumps(verify(Path(sys.argv[1]).resolve()),indent=2))
