"""Render only the canonical result. Exporters contain no business calculations."""

from datetime import date
from decimal import Decimal
from math import ceil
import os
from pathlib import Path
from xml.sax.saxutils import escape


def reporting_period(result):
    filters = result["meta"]["filters"]
    parts = [f"Reporting year {filters['year']}" if filters.get("year") else "Current snapshot"]
    if filters.get("quarter"):
        parts.append(f"Q{filters['quarter']}")
    if filters.get("month"):
        parts.append(f"Recorded month {filters['month']}")
    if filters.get("program_year"):
        parts.append(f"Program year {filters['program_year']}")
    return ", ".join(parts)


def metadata(result):
    meta = result["meta"]
    return [
        ("Application", "AssocMap"), ("Report", result["title"]), ("Contract", result["version"]),
        ("Reporting period", reporting_period(result)),
        ("Generated at", meta["generated_at"]), ("Timezone", meta["timezone"]),
        ("Authorized associations", "See the association scope entries below" if meta["scope"] else "No authorized associations in this scope"),
        ("Scope basis", meta["scope_basis"]), ("Currency", "PHP (Philippine peso)"),
    ] + [("Association scope " + str(index), name) for index, name in enumerate(meta["scope"], 1)] + [
        ("Filter: " + key, str(value)) for key, value in meta["filters"].items()]


def pdf(result, destination):
    import reportlab
    from reportlab.lib import colors
    from reportlab.lib.enums import TA_LEFT, TA_RIGHT
    from reportlab.lib.pagesizes import A4, landscape
    from reportlab.lib.styles import ParagraphStyle
    from reportlab.lib.units import mm
    from reportlab.pdfbase import pdfmetrics
    from reportlab.pdfbase.ttfonts import TTFont
    from reportlab.platypus import LongTable, Paragraph, SimpleDocTemplate, Spacer, TableStyle

    bundled = Path(reportlab.__file__).resolve().parent / "fonts"
    regular = os.environ.get("ASSOCMAP_REPORT_FONT_REGULAR") or str(bundled / "Vera.ttf")
    bold = os.environ.get("ASSOCMAP_REPORT_FONT_BOLD") or str(bundled / "VeraBd.ttf")
    for name, path in (("AssocMap", regular), ("AssocMapBold", bold)):
        font_path = Path(path)
        if not font_path.is_absolute() or not font_path.is_file():
            raise ValueError("font_unavailable")
        pdfmetrics.registerFont(TTFont(name, str(font_path)))
    pdfmetrics.registerFontFamily("AssocMap", normal="AssocMap", bold="AssocMapBold", italic="AssocMap", boldItalic="AssocMapBold")
    blue = colors.HexColor("#0B427B")
    muted = colors.HexColor("#566B7E")
    style = ParagraphStyle("Cell", fontName="AssocMap", fontSize=7.5, leading=11, alignment=TA_LEFT,
                           textColor=colors.HexColor("#203348"), wordWrap="CJK", splitLongWords=True)
    numeric_style = ParagraphStyle("Number", parent=style, alignment=TA_RIGHT)
    heading = ParagraphStyle("Heading", parent=style, fontName="AssocMapBold", fontSize=12, leading=17,
                             textColor=blue, spaceBefore=14, spaceAfter=8, keepWithNext=True)
    title_style = ParagraphStyle("Title", parent=heading, fontSize=22, leading=29, spaceBefore=3, spaceAfter=10)
    caption = ParagraphStyle("Caption", parent=style, textColor=muted, fontSize=8, leading=12, spaceAfter=4)
    table_heading = ParagraphStyle("TableHeading", parent=style, fontName="AssocMapBold", textColor=colors.white)

    def paragraph(value, selected=style):
        text = "Unobserved / not applicable" if value is None else str(value)
        glyphs = pdfmetrics.getFont(selected.fontName).face.charToGlyph
        if any(not char.isspace() and ord(char) not in glyphs for char in text):
            raise ValueError("font_missing_glyph")
        return Paragraph(escape(text).replace("\n", "<br/>"), selected)

    wide = any(section["rows"] and len(section["columns"]) > 6 for section in result["sections"])
    page_size = landscape(A4) if wide else A4
    doc = SimpleDocTemplate(str(destination), pagesize=page_size, rightMargin=15 * mm, leftMargin=15 * mm,
                            topMargin=20 * mm, bottomMargin=19 * mm, title="AssocMap - " + result["title"], author="AssocMap")

    def table(columns, rows):
        header = [paragraph(column["label"], table_heading) for column in columns]
        body = []
        for row in rows:
            cells = []
            for column in columns:
                value = row[column["key"]]
                kind = column["type"]
                if value is not None and kind == "decimal":
                    value = format(Decimal(value), ",.2f")
                elif value is not None and kind == "integer" and column["key"] not in ("year", "program_year", "quarter", "month"):
                    value = format(value, ",")
                cells.append(paragraph(value, numeric_style if kind in ("integer", "decimal") or column.get("align") == "right" else style))
            body.append(cells)
        weights = [4 if column["key"] in ("project", "training", "activity", "measure") else
                   3 if column["key"] == "association" else 1.3 if column["key"] in ("status", "lifecycle", "unit") else
                   2.6 if column["type"] == "text" else 1.5 if column["type"] == "date" else 1.2 for column in columns]
        rendered = LongTable([header] + body, colWidths=[doc.width * weight / sum(weights) for weight in weights],
                             repeatRows=1, splitByRow=1, splitInRow=1, hAlign="LEFT")
        rendered.setStyle(TableStyle([
            ("BACKGROUND", (0, 0), (-1, 0), blue),
            ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#F3F6F9")]),
            ("LINEBELOW", (0, 0), (-1, 0), 0.7, blue),
            ("LINEBELOW", (0, 1), (-1, -1), 0.3, colors.HexColor("#E0E7EE")),
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ("LEFTPADDING", (0, 0), (-1, -1), 6), ("RIGHTPADDING", (0, 0), (-1, -1), 6),
            ("TOPPADDING", (0, 0), (-1, -1), 4), ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
        ]))
        return rendered

    elements = [paragraph("ASSOCMAP", caption), paragraph(result["title"], title_style),
                paragraph(reporting_period(result), caption),
                paragraph("Generated " + result["meta"]["generated_at"] + " (" + result["meta"]["timezone"] + ")", caption),
                paragraph("Scope: " + result["meta"]["scope_basis"], caption)]
    if result["empty"]:
        elements.append(paragraph("No recorded results match the selected report scope and filters.", heading))
    measures = [{"section": section["title"], "measure": item["label"],
                 "value": "Unobserved" if item["value"] is None else
                 format(Decimal(item["value"]), ",.2f") if isinstance(item["value"], str) else format(item["value"], ",")}
                for section in result["sections"] for item in section["measures"]]
    elements.append(paragraph("Summary indicators", heading))
    elements.append(paragraph("Recorded values only. Missing observations are not zero. Units and definitions follow each indicator.", caption))
    if measures:
        elements.append(table([{"key": "section", "label": "Report section", "type": "text"},
                               {"key": "measure", "label": "Indicator", "type": "text"},
                               {"key": "value", "label": "Recorded value", "type": "text", "align": "right"}], measures))
    else:
        elements.append(paragraph("No summary indicators are available for this selection.", caption))

    elements.append(paragraph("Reporting scope and applied filters", heading))
    for label, value in metadata(result):
        if label not in ("Application", "Report", "Reporting period", "Generated at", "Timezone", "Scope basis"):
            elements.append(paragraph(label + ": " + value, caption))
    for section in result["sections"]:
        elements.append(paragraph(section["title"], heading))
        if section["rows"]:
            elements.append(table(section["columns"], section["rows"]))
        else:
            elements.append(paragraph("No recorded rows for this section.", caption))
        elements.extend(paragraph(note, caption) for note in section["notes"])
        elements.append(Spacer(1, 3 * mm))
    elements.append(paragraph("Data quality and interpretation", heading))
    elements.extend(paragraph(note, caption) for note in result["notes"])

    def footer(canvas, document):
        canvas.saveState()
        canvas.setStrokeColor(blue)
        canvas.setLineWidth(0.6)
        canvas.line(15 * mm, page_size[1] - 14 * mm, page_size[0] - 15 * mm, page_size[1] - 14 * mm)
        if document.page > 1:
            canvas.setFont("AssocMap", 7.5)
            canvas.setFillColor(muted)
            canvas.drawString(15 * mm, page_size[1] - 11 * mm, "AssocMap | " + result["title"])
        canvas.setStrokeColor(colors.HexColor("#DFE7EE"))
        canvas.line(15 * mm, 15 * mm, page_size[0] - 15 * mm, 15 * mm)
        canvas.setFont("AssocMap", 7)
        canvas.setFillColor(muted)
        canvas.drawString(15 * mm, 10 * mm, "AssocMap | " + result["meta"]["timezone"] + " | Authorized report scope")
        canvas.drawRightString(page_size[0] - 15 * mm, 10 * mm, "Page " + str(document.page))
        canvas.restoreState()

    doc.build(elements, onFirstPage=footer, onLaterPages=footer)


def xlsx(result, destination):
    from openpyxl import Workbook
    from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
    from openpyxl.utils import get_column_letter
    from openpyxl.worksheet.page import PageMargins

    workbook = Workbook()
    workbook.remove(workbook.active)
    workbook.properties.creator = "AssocMap"
    workbook.properties.title = result["title"]
    workbook.properties.subject = reporting_period(result)
    blue, sage, muted = "0B427B", "63876E", "566B7E"

    def literal(cell, value):
        # Explicit string cells prevent formula injection, including leading whitespace.
        cell.value = str(value)
        cell.data_type = "s"

    def sheet(title, columns, rows, *, header_row=1, widths=None):
        ws = workbook.create_sheet(title[:31])
        ws.sheet_view.showGridLines = False
        ws.sheet_properties.tabColor = blue
        widths = widths or [38 if column["type"] == "text" else 18 for column in columns]
        for index, (column, width) in enumerate(zip(columns, widths), 1):
            ws.column_dimensions[get_column_letter(index)].width = width
            cell = ws.cell(header_row, index)
            literal(cell, column["label"])
            cell.font = Font(name="Calibri", size=10, bold=True, color="FFFFFF")
            cell.fill = PatternFill("solid", fgColor=blue)
            cell.alignment = Alignment(wrap_text=True, vertical="center")
        for row_index, row in enumerate(rows, header_row + 1):
            height = 27
            for column_index, column in enumerate(columns, 1):
                cell = ws.cell(row_index, column_index)
                value, kind = row[column["key"]], column["type"]
                if value is not None:
                    if kind == "integer":
                        cell.value = value
                        cell.number_format = "0" if column["key"] in ("year", "program_year", "quarter", "month") else "#,##0"
                    elif kind == "decimal":
                        decimal = Decimal(value)
                        # Excel numeric cells provide about 15 significant digits.
                        if len(decimal.as_tuple().digits) > 15:
                            raise ValueError("xlsx_numeric_precision_limit")
                        cell.value = decimal
                        cell.number_format = "#,##0.00"
                    elif kind == "date":
                        cell.value = date.fromisoformat(value)
                        cell.number_format = "yyyy-mm-dd"
                    else:
                        literal(cell, value)
                cell.font = Font(name="Calibri", size=10, color="203348")
                cell.alignment = Alignment(wrap_text=True, vertical="top", horizontal="right" if kind in ("integer", "decimal") else "left")
                cell.border = Border(bottom=Side(style="hair", color="E0E7EE"))
                if (row_index - header_row) % 2:
                    cell.fill = PatternFill("solid", fgColor="F1F5F9")
                if value is not None:
                    lines = sum(max(1, ceil(len(line) / max(8, widths[column_index - 1] * 1.1))) for line in str(value).split("\n"))
                    height = max(height, 15 * lines + 10)
            ws.row_dimensions[row_index].height = min(409, height)
        ws.freeze_panes = f"A{header_row + 1}"
        ws.auto_filter.ref = f"A{header_row}:{get_column_letter(len(columns))}{max(header_row, header_row + len(rows))}"
        ws.row_dimensions[header_row].height = 32
        ws.print_title_rows = f"{header_row}:{header_row}"
        ws.print_area = ws.dimensions
        ws.page_setup.orientation = "landscape" if sum(widths) > 110 else "portrait"
        ws.page_setup.paperSize = ws.PAPERSIZE_A4
        ws.page_setup.fitToWidth, ws.page_setup.fitToHeight = 1, 0
        ws.sheet_properties.pageSetUpPr.fitToPage = True
        ws.page_margins = PageMargins(left=0.3, right=0.3, top=0.65, bottom=0.55, header=0.25, footer=0.25)
        ws.oddHeader.left.text = "AssocMap - " + result["title"].replace("&", "&&")
        ws.oddHeader.right.text = reporting_period(result)
        ws.oddHeader.left.size = ws.oddHeader.right.size = 8
        ws.oddFooter.left.text = "Asia/Manila. Recorded values only"
        ws.oddFooter.right.text = "Page &P of &N"
        return ws

    summary = []
    for section in result["sections"]:
        for item in section["measures"]:
            summary.append({"section": section["title"], "measure": item["label"],
                "count": item["value"] if type(item["value"]) is int else None,
                "amount": item["value"] if isinstance(item["value"], str) else None,
                "coverage": "Unobserved" if item["value"] is None else "Recorded data only"})
    overview = sheet("Summary", [{"key": "section", "label": "Section", "type": "text"}, {"key": "measure", "label": "Measure", "type": "text"},
        {"key": "count", "label": "Count", "type": "integer"}, {"key": "amount", "label": "Recorded amount", "type": "decimal"},
        {"key": "coverage", "label": "Observation basis", "type": "text"}], summary, header_row=7, widths=[28, 62, 18, 22, 26])
    for row, text in [(1, "AssocMap - " + result["title"]), (2, reporting_period(result)),
                      (3, "Generated " + result["meta"]["generated_at"] + " (" + result["meta"]["timezone"] + ")"),
                      (4, "Scope: " + result["meta"]["scope_basis"]),
                      (6, "No recorded results match the selected scope and filters." if result["empty"] else
                       "Recorded indicators. Units follow each measure label. Blank numeric cells are unobserved / not applicable.")]:
        overview.merge_cells(start_row=row, start_column=1, end_row=row, end_column=5)
        cell = overview.cell(row, 1)
        literal(cell, text)
        cell.alignment = Alignment(wrap_text=True, vertical="center")
        cell.font = Font(name="Calibri", size=18 if row == 1 else 10, bold=row in (1, 2), color="FFFFFF" if row == 1 else muted)
        if row == 1:
            cell.fill = PatternFill("solid", fgColor=blue)
        overview.row_dimensions[row].height = 44 if row == 1 else 34 if row == 4 else 28
    overview.print_area = overview.dimensions
    quality = [{"label": "Report", "value": note} for note in result["notes"]]
    quality.append({"label": "Blank numeric/date cells", "value": "Unobserved or not applicable; a recorded numeric zero remains a numeric zero."})
    if result["empty"]:
        quality.append({"label": "Empty result", "value": "No recorded results match the selected scope and filters."})
    for index, section in enumerate(result["sections"], 1):
        sheet(f"{index:02d} {section['key'].replace('_', ' ')}", section["columns"], section["rows"])
        quality.extend({"label": section["title"], "value": note} for note in section["notes"])
        if not section["rows"]:
            quality.append({"label": section["title"], "value": "No recorded rows for this section."})
    text_columns = [{"key": "label", "label": "Item", "type": "text"}, {"key": "value", "label": "Value", "type": "text"}]
    sheet("Report Metadata", text_columns, [{"label": key, "value": value} for key, value in metadata(result)], widths=[32, 100])
    sheet("Data Quality", text_columns, quality, widths=[32, 100]).sheet_properties.tabColor = sage
    workbook.active = 0
    workbook.save(destination)
    workbook.close()
