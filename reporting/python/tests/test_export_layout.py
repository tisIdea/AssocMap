"""Isolated export presentation checks against the unchanged canonical result."""

from copy import deepcopy
import json
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
from test_analytics import fixture, uid
from analytics import calculate
from contracts import TITLES, validate
from exporters import pdf, xlsx
from verify_exports import verify
from pypdf import PdfReader


def export_fixture(report="comprehensive"):
    payload = fixture(report)
    data = payload["datasets"]
    data["associations"][0]["name"] = "Peñafrancia Synthetic Association"
    payload["meta"]["scope"] = [data["associations"][0]["name"]]
    if report in ("associations", "members", "projects"):
        payload["meta"]["filters"].pop("year")
    data["classifications"] = [{"member_id": uid(4), "classification_id": 1, "classification": "Synthetic classification"}]
    data["trainings"] = [{"id": uid(20), "association_id": uid(1), "title": "Synthetic training",
        "program_year": 2026, "start_date": "2026-01-15", "end_date": None, "status": "completed",
        "archived_at": None, "component": "Synthetic component"}]
    data["participants"] = [{"training_id": uid(20), "member_id": uid(4), "attendance_status": "present"}]
    data["activities"] = [{"id": uid(21), "association_id": uid(1), "title": "Synthetic program activity",
        "program_year": 2026, "activity_date": "2026-02-01", "status": "completed", "archived_at": None,
        "component": "Synthetic component"}]
    data["monitoring"], data["production"] = [], []
    for quarter in range(1, 5):
        data["monitoring"].append({"id": uid(60 + quarter), "project_id": uid(5), "year": 2026,
            "quarter": quarter, "monitoring_date": f"2026-{quarter * 3:02d}-01", "project_status": "ongoing"})
        data["production"].append({"id": uid(70 + quarter), "monitoring_record_id": uid(60 + quarter),
            "project_product_id": uid(8), "product": "Synthetic product", "target_output": "20.00",
            "actual_output": None if quarter == 2 else "0.00" if quarter == 1 else f"{quarter * 3}.00", "unit": "kg"})
        for month in range(quarter * 3 - 2, quarter * 3 + 1):
            if month != 5:
                data["monthly"].append({"production_id": uid(70 + quarter), "month": month,
                    "activity_count": 0 if quarter == 1 else 1, "total_quantity": "0.00" if quarter == 1 else f"{quarter}.00"})
        if quarter != 2:
            data["income"].append({"id": uid(80 + quarter), "production_id": uid(70 + quarter),
                "period_type": "quarterly", "month": None, "gross_income": "0.00" if quarter == 1 else "1250.50"})
    if report == "production":
        payload["meta"]["filters"].update(project_id=uid(5), product_id=uid(8))
    return calculate(validate(payload))


def export_cases():
    cases = {name: export_fixture(name) for name in TITLES}
    payload = fixture("income")
    cases["empty"] = calculate(validate(payload))
    payload = fixture("projects")
    payload["meta"]["filters"].pop("year")
    original = deepcopy(payload["datasets"]["projects"][0])
    payload["datasets"]["projects"] = [original] + [
        {**original, "id": uid(1000 + index), "title": f"Synthetic project {index:03d} - recorded livelihood project"}
        for index in range(1, 181)]
    payload["datasets"]["projects"][1]["title"] = "=SUM(1,2)"
    payload["datasets"]["projects"][2]["title"] = "Long synthetic project name " + "recorded livelihood activity " * 20
    cases["long-records"] = calculate(validate(payload))
    return cases


class ExportLayoutTest(unittest.TestCase):
    def test_all_report_types_empty_and_long_exports_preserve_canonical_values(self):
        with tempfile.TemporaryDirectory(prefix="assocmap-export-layout-") as directory:
            root = Path(directory)
            cases = export_cases()
            for name, result in cases.items():
                snapshot = deepcopy(result)
                (root / f"{name}.json").write_text(json.dumps(result), encoding="utf-8")
                pdf(result, root / f"{name}.pdf")
                xlsx(result, root / f"{name}.xlsx")
                self.assertEqual(snapshot, result, "Presentation must not mutate the canonical result.")
            checks = verify(root)
            self.assertEqual(len(cases) * 2, len(checks))
            reader = PdfReader(root / "long-records.pdf")
            text = " ".join(" ".join(page.extract_text().split()) for page in reader.pages)
            self.assertIn("Synthetic project 180", text)
            self.assertGreater(len(reader.pages), 2)
            self.assertGreater(sum("Project" in page.extract_text() and "Association" in page.extract_text() for page in reader.pages), 1)


if __name__ == "__main__":
    unittest.main()
