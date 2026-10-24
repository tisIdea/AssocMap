"""Reopen the actual synthetic HTTP response bodies, including scope and filters."""

import json
from pathlib import Path
import sys

from openpyxl import load_workbook
from pypdf import PdfReader

sys.path.insert(0, str(Path(__file__).resolve().parent))
from verify_exports import verify


def verify_http(directory):
    checks = verify(directory)
    sources = sorted(directory.glob("*.json"))
    results = {source.stem: json.loads(source.read_text(encoding="utf-8")) for source in sources}
    all_names = set(results["admin-associations"]["meta"]["scope"])
    for stem, result in results.items():
        meta = result["meta"]
        workbook = load_workbook(directory / (stem + ".xlsx"), data_only=False, keep_links=False)
        try:
            metadata = dict(workbook["Report Metadata"].iter_rows(min_row=2, values_only=True))
            assert metadata["Report"] == result["title"]
            assert metadata["Timezone"] == meta["timezone"]
            scopes = [value for key, value in metadata.items() if key.startswith("Association scope ")]
            assert scopes == meta["scope"], "XLSX contains an incorrect association scope."
            assert {key.removeprefix("Filter: "): value for key, value in metadata.items()
                    if key.startswith("Filter: ")} == {key: str(value) for key, value in meta["filters"].items()}
            strings = {cell.value for sheet in workbook for row in sheet for cell in row if isinstance(cell.value, str)}
            assert not (all_names - set(scopes)) & strings, "XLSX contains an unauthorized association."
        finally:
            workbook.close()
        reader = PdfReader(directory / (stem + ".pdf"), strict=True)
        text = "".join("".join((page.extract_text() or "").split()) for page in reader.pages)
        for value in meta["scope"]:
            assert "".join(value.split()) in text, "PDF lost an authorized association."
        for key, value in meta["filters"].items():
            assert "".join(("Filter: " + key + ": " + str(value)).split()) in text, "PDF filter metadata differs."
        for value in all_names - set(meta["scope"]):
            assert "".join(value.split()) not in text, "PDF contains an unauthorized association."
        for section in result["sections"]:
            for row in section["rows"]:
                for value in row.values():
                    if value is not None:
                        assert "".join(str(value).split()) in text, "PDF lost a canonical cell value."
        if result["empty"]:
            assert "Norecordedresultsmatchtheselectedreportscopeandfilters." in text
        checks.append({"test": stem + " HTTP PDF/XLSX scope, filters and PDF cells", "status": "PASS"})
    return checks


if __name__ == "__main__":
    evidence = verify_http(Path(sys.argv[1]).resolve())
    print(json.dumps(evidence, indent=2))
