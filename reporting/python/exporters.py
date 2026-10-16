"""Render only the canonical result. Exporters contain no business calculations."""

from datetime import date
from decimal import Decimal
import os
from pathlib import Path
from xml.sax.saxutils import escape


def metadata(result):
    meta = result["meta"]
    return [
        ("Application", "AssocMap"), ("Report", result["title"]), ("Contract", result["version"]),
        ("Generated at", meta["generated_at"]), ("Timezone", meta["timezone"]),
        ("Authorized associations", "See the association scope entries below" if meta["scope"] else "No authorized associations in this scope"),
        ("Scope basis", meta["scope_basis"]), ("Currency", "PHP (Philippine peso)"),
    ] + [("Association scope " + str(index), name) for index, name in enumerate(meta["scope"], 1)] + [
        ("Filter: " + key, str(value)) for key, value in meta["filters"].items()]


def pdf(result, destination):
    import reportlab
    from reportlab.lib import colors
    from reportlab.lib.enums import TA_LEFT
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
    style = ParagraphStyle("Cell", fontName="AssocMap", fontSize=7, leading=10, alignment=TA_LEFT,
                           wordWrap="CJK", splitLongWords=True, spaceAfter=3)
    heading = ParagraphStyle("Heading", parent=style, fontName="AssocMapBold", fontSize=12, leading=16, spaceBefore=12, spaceAfter=8, keepWithNext=True)

    def paragraph(value, selected=style):
        text = "Unobserved / not applicable" if value is None else str(value)
        glyphs = pdfmetrics.getFont(selected.fontName).face.charToGlyph
        if any(not char.isspace() and ord(char) not in glyphs for char in text):
            raise ValueError("font_missing_glyph")
        return Paragraph(escape(text).replace("\n", "<br/>"), selected)

    wide = any(len(section["columns"]) > 6 for section in result["sections"])
    page_size = landscape(A4) if wide else A4
    doc = SimpleDocTemplate(str(destination), pagesize=page_size, rightMargin=15 * mm, leftMargin=15 * mm,
                            topMargin=18 * mm, bottomMargin=18 * mm, title="AssocMap - " + result["title"], author="AssocMap")
    elements = [paragraph("ASSOCMAP", heading), paragraph(result["title"], heading)]
    for label, value in metadata(result):
        elements.append(paragraph(label + ": " + value))
    if result["empty"]:
        elements.append(paragraph("No recorded results match the selected report scope and filters.", heading))
    elements.append(paragraph("Data quality and interpretation", heading))
    elements.extend(paragraph(note) for note in result["notes"])
    for section in result["sections"]:
        elements.append(paragraph(section["title"], heading))
        for item in section["measures"]:
            value = "Unobserved" if item["value"] is None else str(item["value"])
            elements.append(paragraph(item["label"] + ": " + value))
        elements.extend(paragraph(note) for note in section["notes"])
        if not section["rows"]:
            elements.append(paragraph("No recorded rows for this section."))
            continue
        columns = section["columns"]
        header = [paragraph(column["label"]) for column in columns]
        body = [[paragraph(row[column["key"]]) for column in columns] for row in section["rows"]]
        weights = [2.4 if column["type"] == "text" else 1.2 for column in columns]
        widths = [doc.width * weight / sum(weights) for weight in weights]
        table = LongTable([header] + body, colWidths=widths, repeatRows=1, splitByRow=1, splitInRow=1, hAlign="LEFT")
        table.setStyle(TableStyle([
            ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#e8f0f9")),
            ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#f7f9fc")]),
            ("GRID", (0, 0), (-1, -1), 0.3, colors.HexColor("#d3dde8")),
            ("VALIGN", (0, 0), (-1, -1), "TOP"), ("LEFTPADDING", (0, 0), (-1, -1), 5),
            ("RIGHTPADDING", (0, 0), (-1, -1), 5), ("TOPPADDING", (0, 0), (-1, -1), 5),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
        ]))
        elements.extend([table, Spacer(1, 4 * mm)])

    def footer(canvas, document):
        canvas.saveState()
        canvas.setFont("AssocMap", 8)
        canvas.drawString(15 * mm, 10 * mm, "AssocMap | " + result["meta"]["timezone"])
        canvas.drawRightString(page_size[0] - 15 * mm, 10 * mm, "Page " + str(document.page))
        canvas.restoreState()

    doc.build(elements, onFirstPage=footer, onLaterPages=footer)


def xlsx(result, destination):
    from openpyxl import Workbook
    from openpyxl.styles import Alignment, Font, PatternFill
    from openpyxl.utils import get_column_letter

    workbook = Workbook()
    workbook.remove(workbook.active)

    def literal(cell, value):
        # Explicit string cells prevent formula injection, including leading whitespace.
        cell.value = str(value)
        cell.data_type = "s"

    def sheet(title, columns, rows):
        ws = workbook.create_sheet(title[:31])
        for index, column in enumerate(columns, 1):
            cell = ws.cell(1, index)
            literal(cell, column["label"])
            cell.font = Font(bold=True, color="FFFFFF")
            cell.fill = PatternFill("solid", fgColor="245C90")
            cell.alignment = Alignment(wrap_text=True, vertical="top")
        for row_index, row in enumerate(rows, 2):
            for column_index, column in enumerate(columns, 1):
                cell = ws.cell(row_index, column_index)
                value = row[column["key"]]
                if value is None:
                    continue
                kind = column["type"]
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
                cell.alignment = Alignment(wrap_text=True, vertical="top")
                if row_index % 2 == 0:
                    cell.fill = PatternFill("solid", fgColor="F1F5FA")
        ws.freeze_panes = "A2"
        ws.auto_filter.ref = ws.dimensions
        ws.row_dimensions[1].height = 32
        for index, column in enumerate(columns, 1):
            ws.column_dimensions[get_column_letter(index)].width = 42 if column["type"] == "text" else 20
        return ws

    text_columns = [{"key": "label", "label": "Item", "type": "text"}, {"key": "value", "label": "Value", "type": "text"}]
    sheet("Report Metadata", text_columns, [{"label": key, "value": value} for key, value in metadata(result)])
    summary = []
    for section in result["sections"]:
        for item in section["measures"]:
            summary.append({"section": section["title"], "measure": item["label"],
                "count": item["value"] if type(item["value"]) is int else None,
                "amount": item["value"] if isinstance(item["value"], str) else None,
                "coverage": "Unobserved" if item["value"] is None else "Recorded data only"})
    sheet("Summary", [{"key": "section", "label": "Section", "type": "text"}, {"key": "measure", "label": "Measure", "type": "text"},
        {"key": "count", "label": "Count", "type": "integer"}, {"key": "amount", "label": "Recorded amount", "type": "decimal"},
        {"key": "coverage", "label": "Observation basis", "type": "text"}], summary)
    quality = [{"label": "Report", "value": note} for note in result["notes"]]
    quality.append({"label": "Blank numeric/date cells", "value": "Unobserved or not applicable; a recorded numeric zero remains a numeric zero."})
    if result["empty"]:
        quality.append({"label": "Empty result", "value": "No recorded results match the selected scope and filters."})
    for index, section in enumerate(result["sections"], 1):
        sheet(f"{index:02d} {section['key'].replace('_', ' ')}", section["columns"], section["rows"])
        quality.extend({"label": section["title"], "value": note} for note in section["notes"])
        if not section["rows"]:
            quality.append({"label": section["title"], "value": "No recorded rows for this section."})
    sheet("Data Quality", text_columns, quality)
    workbook.save(destination)
    workbook.close()
