"""Synthetic regression coverage for shared reporting calculations and contracts."""

from copy import deepcopy
from pathlib import Path
import sys
import tempfile
import unittest
from uuid import UUID

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from analytics import calculate
from contracts import ContractError, SCHEMAS, VERSION, validate


def uid(number):
    return str(UUID(int=number))


def fixture(report="comprehensive"):
    data = {name: [] for name in SCHEMAS}
    data["associations"] = [{"id": uid(1), "name": "Synthetic association", "status": "active", "archived_at": None,
        "municipality_id": uid(2), "municipality": "Synthetic town", "barangay_id": uid(3), "barangay": "Synthetic barangay"}]
    data["members"] = [{"id": uid(4), "association_id": uid(1), "status": "inactive", "archived_at": "2026-01-01T00:00:00+08:00"}]
    data["projects"] = [{"id": uid(5), "association_id": uid(1), "title": "Synthetic project", "program_year": 2026,
        "status": "ongoing", "archived_at": None, "component": "Synthetic component", "category": "Synthetic category"}]
    data["monitoring"] = [{"id": uid(6), "project_id": uid(5), "year": 2026, "quarter": 1,
        "monitoring_date": "2026-02-01", "project_status": "ongoing"}]
    data["production"] = [{"id": uid(7), "monitoring_record_id": uid(6), "project_product_id": uid(8), "product": "Synthetic product",
        "target_output": "20.00", "actual_output": "10.00", "unit": "kg"}]
    return {"version": VERSION, "action": "analytics", "meta": {"report_type": report,
        "filters": {"report_type": report, "year": 2026}, "generated_at": "2026-10-15T12:00:00+08:00",
        "timezone": "Asia/Manila", "scope": ["Synthetic association"], "scope_basis": "Synthetic authorized fixture", "currency": "PHP"}, "datasets": data}


def by_key(result, key):
    return next(section for section in result["sections"] if section["key"] == key)


class AnalyticsTest(unittest.TestCase):
    def result(self, payload):
        return calculate(validate(payload))

    def test_geography_keeps_same_names_separate_by_id_and_reconciles_authorized_population(self):
        payload = fixture("associations")
        original = payload["datasets"]["associations"][0]
        payload["datasets"]["associations"] += [
            {**original, "id": uid(31), "archived_at": "2026-01-01T00:00:00+08:00"},
            {**original, "id": uid(32), "barangay_id": uid(33)},
            {**original, "id": uid(34), "municipality_id": uid(35), "barangay_id": uid(36)},
        ]
        payload["meta"]["scope"] = [row["name"] for row in payload["datasets"]["associations"]]
        result = self.result(payload)
        rows = by_key(result, "association_geography")["rows"]
        self.assertEqual(3, len(rows))
        self.assertEqual([2, 1, 1], [row["count"] for row in rows])
        self.assertEqual([3, 3, 1], [row["municipality_total"] for row in rows])
        self.assertEqual(4, sum(row["count"] for row in rows))
        self.assertEqual(4, sum({row["municipality_id"]: row["municipality_total"] for row in rows}.values()))
        self.assertEqual(4, by_key(result, "associations")["measures"][0]["value"])
        self.assertEqual(1, by_key(result, "associations")["measures"][2]["value"])

    def test_geography_contract_rejects_conflicting_parent_or_labels_and_duplicate_associations(self):
        for change in ({"municipality_id": uid(99)}, {"municipality": "Conflicting name"}, {"barangay": "Conflicting name"}, {"id": uid(1)}):
            with self.subTest(change=change):
                payload = fixture("associations")
                payload["datasets"]["associations"].append({**payload["datasets"]["associations"][0], "id": uid(98), **change})
                payload["meta"]["scope"] *= 2
                with self.assertRaises(ContractError):
                    validate(payload)

    def test_all_report_types_handle_an_empty_authorized_dataset(self):
        from contracts import TITLES
        for report in TITLES:
            with self.subTest(report=report):
                payload=fixture(report)
                payload["datasets"]={name: [] for name in SCHEMAS}
                payload["meta"]["scope"]=[]
                result=self.result(payload)
                self.assertTrue(result["empty"])
                self.assertTrue(all(not section["rows"] for section in result["sections"]))

    def test_missing_actual_output_is_not_converted_to_recorded_zero(self):
        payload=fixture("production")
        payload["datasets"]["production"][0]["actual_output"]=None
        result=self.result(payload)
        self.assertIsNone(by_key(result,"production")["rows"][0]["actual"])
        self.assertEqual(0,by_key(result,"production")["measures"][1]["value"])

    def test_multiple_projects_and_associations_keep_independent_observations(self):
        payload=fixture()
        data=payload["datasets"]
        association=deepcopy(data["associations"][0]); association.update(id=uid(60),name="Other synthetic association")
        data["associations"].append(association); payload["meta"]["scope"].append(association["name"])
        project=deepcopy(data["projects"][0]); project.update(id=uid(61),association_id=uid(60),status="terminated")
        data["projects"].append(project)
        record=deepcopy(data["monitoring"][0]); record.update(id=uid(62),project_id=uid(61))
        data["monitoring"].append(record)
        production=deepcopy(data["production"][0]); production.update(id=uid(63),monitoring_record_id=uid(62),project_product_id=uid(64),unit="pieces",actual_output="5.00")
        data["production"].append(production)
        result=self.result(payload)
        self.assertEqual(2,by_key(result,"projects")["measures"][0]["value"])
        self.assertEqual(["Synthetic association","Other synthetic association"], [row["association"] for row in by_key(result,"production")["rows"]])
        self.assertEqual(["10.00","5.00"],[row["actual"] for row in by_key(result,"production")["rows"]])

    def test_valid_ongoing_activities_are_retained_in_activity_and_comprehensive_reports(self):
        for report in ("activities", "comprehensive"):
            with self.subTest(report=report):
                payload = fixture(report)
                payload["datasets"]["activities"] = [{"id": uid(50), "association_id": uid(1),
                    "title": "Synthetic ongoing activity", "program_year": 2026, "activity_date": "2026-01-15",
                    "status": "ongoing", "archived_at": None, "component": "Synthetic component"}]
                activities = by_key(self.result(payload), "activities")
                self.assertEqual("ongoing", activities["rows"][0]["status"])
                self.assertEqual(1, activities["measures"][0]["value"])
                payload["datasets"]["activities"][0]["status"] = "suspended"
                with self.assertRaises(ContractError):
                    validate(payload)

    def test_monthly_production_rows_match_the_declared_shared_result_columns(self):
        for report in ("production", "comprehensive"):
            with self.subTest(report=report):
                payload = fixture(report)
                payload["datasets"]["monthly"] = [{"production_id": uid(7), "month": 1,
                    "activity_count": 0, "total_quantity": "0.00"}]
                for section in self.result(payload)["sections"]:
                    keys = {column["key"] for column in section["columns"]}
                    for row in section["rows"]:
                        self.assertEqual(keys, set(row), section["key"])

    def test_classification_joins_do_not_inflate_archived_member_total(self):
        payload = fixture("members")
        payload["datasets"]["classifications"] = [
            {"member_id": uid(4), "classification_id": index, "classification": "Class " + str(index)} for index in (1, 2)]
        result = self.result(payload)
        members = by_key(result, "members")
        self.assertEqual(1, members["measures"][0]["value"])
        self.assertEqual(1, members["rows"][0]["archived"])
        self.assertEqual([1, 1], [row["members"] for row in by_key(result, "classifications")["rows"]])

    def test_monthly_details_are_not_added_to_quarterly_output_or_other_units(self):
        payload = fixture("production")
        payload["datasets"]["monthly"] = [{"production_id": uid(7), "month": 1, "activity_count": 2, "total_quantity": "8.00"}]
        second = deepcopy(payload["datasets"]["production"][0])
        second.update(id=uid(9), project_product_id=uid(10), unit="pieces", actual_output="5.00")
        payload["datasets"]["production"].append(second)
        result = self.result(payload)
        self.assertEqual(["10.00", "5.00"], [row["actual"] for row in by_key(result, "production")["rows"]])
        self.assertEqual("8.00", by_key(result, "monthly_production")["rows"][0]["quantity"])
        self.assertEqual(2, len(by_key(result, "production_coverage")["rows"]))

    def test_missing_income_is_unobserved_and_recorded_zero_is_numeric_zero(self):
        payload = fixture("income")
        result = self.result(payload)
        self.assertIsNone(by_key(result, "income")["measures"][1]["value"])
        payload["datasets"]["income"] = [{"id": uid(11), "production_id": uid(7), "period_type": "quarterly", "month": None, "gross_income": "0.00"}]
        result = self.result(payload)
        self.assertEqual("0.00", by_key(result, "income")["measures"][1]["value"])

    def test_calculated_quarter_and_zero_month_remain_separate_observations(self):
        payload = fixture("production")
        payload["datasets"]["production"][0]["actual_output"] = "300.30"
        payload["datasets"]["monthly"] = [
            {"production_id": uid(7), "month": month, "activity_count": count, "total_quantity": quantity}
            for month, count, quantity in [(1, 0, "0.00"), (2, 2, "125.10"), (3, 3, "175.20")]]
        result = self.result(payload)
        self.assertEqual("300.30", by_key(result, "production")["rows"][0]["actual"])
        self.assertEqual(["0.00", "125.10", "175.20"],
                         [row["quantity"] for row in by_key(result, "monthly_production")["rows"]])
        self.assertEqual(0, by_key(result, "monthly_production")["rows"][0]["activities"])
        payload["meta"]["filters"]["month"] = 1
        payload["datasets"]["monthly"] = payload["datasets"]["monthly"][:1]
        result = self.result(payload)
        self.assertEqual("300.30", by_key(result, "production")["rows"][0]["actual"])
        self.assertEqual("0.00", by_key(result, "monthly_production")["rows"][0]["quantity"])

    def test_current_project_status_is_independent_of_historical_monitoring_snapshot(self):
        payload = fixture()
        payload["datasets"]["projects"][0]["status"] = "completed"
        payload["datasets"]["monitoring"][0]["project_status"] = "suspended"
        result = self.result(payload)
        self.assertEqual("completed", by_key(result, "project_status")["rows"][0]["label"])
        self.assertEqual("suspended", by_key(result, "monitoring_status")["rows"][0]["label"])

    def test_terminated_project_is_distinct_from_successful_completion_and_keeps_history(self):
        baseline = self.result(fixture())
        payload = fixture()
        payload["datasets"]["projects"][0]["status"] = "terminated"
        result = self.result(payload)
        self.assertEqual("terminated", by_key(result, "project_status")["rows"][0]["label"])
        self.assertEqual("ongoing", by_key(result, "monitoring_status")["rows"][0]["label"])
        for key in ("production", "monthly_production", "income"):
            self.assertEqual(by_key(baseline, key), by_key(result, key))
        other = deepcopy(payload["datasets"]["projects"][0])
        other.update(id=uid(99), status="completed")
        payload["datasets"]["projects"].append(other)
        labels = {row["label"] for row in by_key(self.result(payload), "project_status")["rows"]}
        self.assertEqual({"completed", "terminated"}, labels)

    def test_month_selection_excludes_quarterly_income_without_allocating_it(self):
        payload = fixture("income")
        payload["meta"]["filters"]["month"] = 1
        payload["datasets"]["income"] = [{"id": uid(11), "production_id": uid(7), "period_type": "quarterly", "month": None, "gross_income": "10.00"}]
        result = self.result(payload)
        self.assertTrue(result["empty"])
        self.assertEqual([], by_key(result, "income")["rows"])
        self.assertIn("1 quarterly entries excluded", " ".join(by_key(result, "income")["notes"]))

    def test_decimal_addition_is_exact(self):
        payload = fixture("income")
        payload["datasets"]["income"] = [{"id": uid(11 + month), "production_id": uid(7), "period_type": "monthly", "month": month,
            "gross_income": value} for month, value in [(1, "0.10"), (2, "0.20")]]
        result = self.result(payload)
        self.assertEqual("0.30", by_key(result, "income")["measures"][1]["value"])

    def test_training_people_and_present_occurrences_remain_distinct(self):
        payload = fixture("trainings")
        for identity in (20, 21):
            payload["datasets"]["trainings"].append({"id": uid(identity), "association_id": uid(1), "title": "Training " + str(identity),
                "program_year": 2026, "start_date": "2026-01-01", "end_date": None, "status": "completed", "archived_at": None, "component": "Component"})
            payload["datasets"]["participants"].append({"training_id": uid(identity), "member_id": uid(4), "attendance_status": "present"})
        measures = {item["label"]: item["value"] for item in by_key(self.result(payload), "trainings")["measures"]}
        self.assertEqual(1, measures["Distinct members recorded"])
        self.assertEqual(2, measures["Present attendance occurrences"])
        self.assertEqual(1, measures["Distinct members marked present"])

    def test_contract_rejects_mixed_income_frequencies_and_wrong_months(self):
        payload = fixture("income")
        payload["datasets"]["income"] = [
            {"id": uid(11), "production_id": uid(7), "period_type": "quarterly", "month": None, "gross_income": "10.00"},
            {"id": uid(12), "production_id": uid(7), "period_type": "monthly", "month": 1, "gross_income": "10.00"}]
        with self.assertRaises(ContractError):
            validate(payload)
        payload["datasets"]["income"] = [payload["datasets"]["income"][1]]
        payload["datasets"]["income"][0]["month"] = 7
        with self.assertRaises(ContractError):
            validate(payload)

    def test_contract_rejects_unscoped_parent_and_duplicate_member_rows(self):
        payload = fixture()
        payload["datasets"]["members"][0]["association_id"] = uid(999)
        with self.assertRaises(ContractError):
            validate(payload)
        payload = fixture()
        payload["datasets"]["members"].append(deepcopy(payload["datasets"]["members"][0]))
        with self.assertRaises(ContractError):
            validate(payload)

    def test_xlsx_text_cannot_become_a_formula_and_decimals_remain_numeric(self):
        from exporters import xlsx
        from openpyxl import load_workbook
        payload = fixture("income")
        payload["datasets"]["associations"][0]["name"] = '=HYPERLINK("https://example.test","synthetic")'
        payload["meta"]["scope"] = [payload["datasets"]["associations"][0]["name"]]
        payload["datasets"]["income"] = [{"id": uid(11), "production_id": uid(7), "period_type": "quarterly", "month": None, "gross_income": "0.30"}]
        result = self.result(payload)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "synthetic.xlsx"
            xlsx(result, path)
            workbook = load_workbook(path)
            details = workbook["01 income"]
            self.assertEqual("s", details["A2"].data_type)
            self.assertTrue(details["A2"].value.startswith("="))
            self.assertEqual("n", details["H2"].data_type)
            self.assertEqual(0.3, details["H2"].value)
            self.assertEqual("0", details["D2"].number_format)
            self.assertEqual("0", details["E2"].number_format)
            workbook.close()


if __name__ == "__main__":
    unittest.main()
