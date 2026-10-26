"""Canonical calculations used unchanged by preview, PDF and XLSX."""

from collections import Counter, defaultdict
from decimal import Decimal

from contracts import TITLES, VERSION, decimal_value, truth


def amount(value):
    return None if value is None else format(value, ".2f")


def measure(label, value):
    return {"label": label, "value": value}


def section(key, title, columns, rows, measures=None, notes=None, chart=None):
    return {"key": key, "title": title, "columns": [{"key": key, "label": label, "type": kind} for key, label, kind in columns],
            "rows": rows, "measures": measures or [], "notes": notes or [], "chart": chart or {}}


def distribution(key, title, records, group, label):
    counts = Counter(row[group] for row in records)
    rows = [{"label": name, "count": count} for name, count in sorted(counts.items())]
    return section(key, title, [("label", label, "text"), ("count", "Recorded count", "integer")], rows,
                   chart={"title": title, "label_key": "label", "value_key": "count", "unit": "records"})


def calculate(payload):
    data = payload["datasets"]
    meta = payload["meta"]
    report = meta["report_type"]
    all_reports = report == "comprehensive"
    sections = []
    geography_section = None
    notes = [
        "Association/member counts and project statuses are current snapshots, not reconstructed historical states.",
        "Archived records are retained within the current authorized association scope. Counts describe recorded data only.",
        "Program year is an administrative cohort. Reporting year/quarter selects training start dates, activity dates and monitoring reporting periods.",
        "No achievement, completion-rate, profit or official trained-member KPI is inferred.",
    ]
    associations = {row["id"]: row for row in data["associations"]}
    projects = {row["id"]: row for row in data["projects"]}
    monitoring = {row["id"]: row for row in data["monitoring"]}
    production = {row["id"]: row for row in data["production"]}

    def association_name(identity):
        return "Program-wide (no association link)" if identity is None else associations[identity]["name"]

    def context(prod):
        record = monitoring[prod["monitoring_record_id"]]
        project = projects[record["project_id"]]
        return {"association": association_name(project["association_id"]), "project": project["title"],
                "product": prod["product"], "year": record["year"], "quarter": record["quarter"]}

    if all_reports or report == "associations":
        rows = [{"association": r["name"], "municipality": r["municipality"], "barangay": r["barangay"],
                 "status": r["status"], "lifecycle": "Archived" if r["archived_at"] else "Unarchived"} for r in data["associations"]]
        sections.append(section("associations", "Associations — current snapshot", [("association", "Association", "text"),
            ("municipality", "Municipality/City", "text"), ("barangay", "Barangay", "text"), ("status", "Current status", "text"),
            ("lifecycle", "Archive state", "text")], rows, [measure("Distinct associations", len(associations)),
            measure("Active and unarchived associations", sum(r["status"] == "active" and not r["archived_at"] for r in data["associations"])),
            measure("Archived associations", sum(bool(r["archived_at"]) for r in data["associations"]))]))
        # Group using IDs; same-named localities do not collapse into one row.
        for level in ("municipality", "barangay"):
            counts = Counter(r[level + "_id"] for r in data["associations"])
            names = {r[level + "_id"]: (r["municipality"] + " / " + r["barangay"] if level == "barangay" else r[level]) for r in data["associations"]}
            rows = [{"label": names[key], "count": value} for key, value in sorted(counts.items(), key=lambda item: (names[item[0]], item[0]))]
            sections.append(section("association_" + level, "Association distribution by " + level,
                [("label", level.title(), "text"), ("count", "Distinct associations", "integer")], rows,
                chart={"title": "Associations by " + level, "label_key": "label", "value_key": "count", "unit": "associations"}))
        sections.append(distribution("association_status", "Current association statuses", data["associations"], "status", "Status"))
        geography = Counter((r["municipality_id"], r["barangay_id"]) for r in data["associations"])
        municipality_counts = Counter(r["municipality_id"] for r in data["associations"])
        names = {(r["municipality_id"], r["barangay_id"]): (r["municipality"], r["barangay"]) for r in data["associations"]}
        rows = [{"municipality_id": municipality, "municipality": names[(municipality, barangay)][0],
                 "barangay_id": barangay, "barangay": names[(municipality, barangay)][1], "count": count,
                 "municipality_total": municipality_counts[municipality]}
                for (municipality, barangay), count in sorted(geography.items(), key=lambda item: (names[item[0]][0], item[0][0], names[item[0]][1], item[0][1]))]
        geography_section = section("association_geography", "Association Distribution by Municipality and Barangay",
            [("municipality_id", "Municipality ID", "text"), ("municipality", "Municipality", "text"),
             ("barangay_id", "Barangay ID", "text"), ("barangay", "Barangay", "text"),
             ("count", "Association count", "integer"), ("municipality_total", "Municipality subtotal", "integer")], rows,
            notes=["Counts use geographic IDs and retained authorized associations, including archived associations. Municipality subtotals repeat for their barangays; do not sum that column."])

    if all_reports or report == "members":
        members = data["members"]
        rows = []
        for identity, assoc in associations.items():
            subset = [r for r in members if r["association_id"] == identity]
            rows.append({"association": assoc["name"], "total": len(subset),
                         "active": sum(r["status"] == "active" and not r["archived_at"] for r in subset),
                         "inactive": sum(r["status"] == "inactive" and not r["archived_at"] for r in subset),
                         "archived": sum(bool(r["archived_at"]) for r in subset)})
        sections.append(section("members", "Official members — current snapshot", [("association", "Association", "text"),
            ("total", "Total retained members", "integer"), ("active", "Active / unarchived", "integer"),
            ("inactive", "Inactive / unarchived", "integer"), ("archived", "Archived", "integer")], rows,
            [measure("Distinct official members", len(members)), measure("Active unarchived members", sum(r["active"] for r in rows)),
             measure("Archived members", sum(r["archived"] for r in rows))],
            ["No applicants, names, contact details, birth dates, registration numbers or private evidence are included."],
            {"title": "Recorded members by association", "label_key": "association", "value_key": "total", "unit": "members"}))
        classified = defaultdict(set)
        names = {}
        for row in data["classifications"]:
            classified[row["classification_id"]].add(row["member_id"])
            names[row["classification_id"]] = row["classification"]
        rows = [{"classification": names[key], "members": len(value)} for key, value in sorted(classified.items())]
        sections.append(section("classifications", "Member classifications", [("classification", "Classification", "text"),
            ("members", "Distinct retained members", "integer")], rows, notes=["A member can have multiple classifications. These counts must not be added to obtain the member total."]))

    if all_reports or report == "projects":
        rows = [{"association": association_name(r["association_id"]), "project": r["title"], "program_year": r["program_year"],
                 "component": r["component"], "category": r["category"], "status": r["status"],
                 "lifecycle": "Archived" if r["archived_at"] else "Unarchived"} for r in data["projects"]]
        sections.append(section("projects", "Project portfolio — current status", [("association", "Association", "text"),
            ("project", "Project", "text"), ("program_year", "Program year", "integer"), ("component", "Component", "text"),
            ("category", "Category", "text"), ("status", "Current status", "text"), ("lifecycle", "Archive state", "text")], rows,
            [measure("Distinct projects", len(projects)), measure("Archived projects", sum(bool(r["archived_at"]) for r in data["projects"]))],
            ["Project portfolio ignores reporting year/quarter. Use program year to select the project cohort."]))
        sections.append(distribution("project_status", "Current project statuses", data["projects"], "status", "Status"))
        sections.append(distribution("project_component", "Projects by component", data["projects"], "component", "Component"))

    if all_reports or report == "trainings":
        participants = data["participants"]
        by_training = defaultdict(list)
        for row in participants:
            by_training[row["training_id"]].append(row)
        rows = []
        for row in data["trainings"]:
            recorded = by_training[row["id"]]
            rows.append({"association": association_name(row["association_id"]), "training": row["title"], "date": row["start_date"],
                "component": row["component"], "status": row["status"], "recorded": len(recorded),
                "present": sum(r["attendance_status"] == "present" for r in recorded),
                "absent": sum(r["attendance_status"] == "absent" for r in recorded),
                "lifecycle": "Archived" if row["archived_at"] else "Unarchived"})
        sections.append(section("trainings", "Training participation", [("association", "Association", "text"), ("training", "Training", "text"),
            ("date", "Start date", "date"), ("component", "Component", "text"), ("status", "Recorded status", "text"),
            ("recorded", "Participant rows", "integer"), ("present", "Present occurrences", "integer"), ("absent", "Absent rows", "integer"),
            ("lifecycle", "Archive state", "text")], rows, [measure("Training activities", len(data["trainings"])),
                measure("Completed training activities", sum(r["status"] == "completed" for r in data["trainings"])),
                measure("Participant rows recorded", len(participants)), measure("Distinct members recorded", len({r["member_id"] for r in participants})),
                measure("Present attendance occurrences", sum(r["attendance_status"] == "present" for r in participants)),
                measure("Distinct members marked present", len({r["member_id"] for r in participants if r["attendance_status"] == "present"}))],
            ["Recorded participants include absent rows and all selected training statuses. Present attendance is reported separately; neither is an official trained-member KPI.",
             "Historical participation of archived members and archived training activities is retained. Dates filter training start dates."]))
        sections.append(distribution("training_component", "Training activities by component", data["trainings"], "component", "Component"))

    if all_reports or report == "production":
        rows = [{**context(r), "unit": r["unit"], "target": amount(decimal_value(r["target_output"])) if r["target_output"] is not None else None,
                 "actual": amount(decimal_value(r["actual_output"])) if r["actual_output"] is not None else None} for r in data["production"]]
        sections.append(section("production", "Quarterly production observations", [("association", "Association", "text"),
            ("project", "Project", "text"), ("product", "Product", "text"), ("year", "Year", "integer"), ("quarter", "Quarter", "integer"),
            ("unit", "Recorded unit", "text"), ("target", "Recorded target", "decimal"), ("actual", "Recorded actual", "decimal")], rows,
            [measure("Production observations", len(rows)), measure("Recorded zero actual observations", sum(r["actual"] == "0.00" for r in rows)),
             measure("Monitoring records without production", len(set(monitoring) - {r["monitoring_record_id"] for r in data["production"]}))],
            ["Each row is one product observation in one quarterly monitoring record. Unlike units and separate quarters are never added together.",
             "Monthly quantities and their saved quarterly Actual Output represent the same production and are not added together. Legacy quarterly values are preserved; missing months are not inferred.",
             "Missing rows are unobserved, not zero. A product filter limits the coverage comparison to that product."]))
        monthly = [{**context(production[r["production_id"]]), "month": r["month"], "activities": r["activity_count"],
                    "quantity": amount(decimal_value(r["total_quantity"])), "unit": production[r["production_id"]]["unit"]} for r in data["monthly"]]
        sections.append(section("monthly_production", "Recorded monthly production details", [("association", "Association", "text"),
            ("project", "Project", "text"), ("product", "Product", "text"), ("year", "Year", "integer"), ("quarter", "Quarter", "integer"), ("month", "Month", "integer"),
            ("activities", "Recorded activities", "integer"), ("quantity", "Monthly quantity", "decimal"), ("unit", "Recorded unit", "text")], monthly,
            [measure("Monthly detail observations", len(monthly))], ["Month filters apply only to these recorded details. The quarterly table remains quarter context; no monthly values are fabricated."]))
        unit_counts = Counter((r["year"], r["quarter"], r["unit"]) for r in rows)
        sections.append(section("production_coverage", "Production observation coverage by period and unit",
            [("period_unit", "Period / recorded unit", "text"), ("count", "Observation count", "integer")],
            [{"period_unit": f"{year} Q{quarter} / {unit}", "count": count} for (year, quarter, unit), count in sorted(unit_counts.items())],
            chart={"title": "Production observation counts (not quantities)", "label_key": "period_unit", "value_key": "count", "unit": "observations"}))

    if all_reports or report == "income":
        month = meta["filters"].get("month")
        incomes = [r for r in data["income"] if month is None or (r["period_type"] == "monthly" and r["month"] == month)]
        rows = [{**context(production[r["production_id"]]), "frequency": r["period_type"], "month": r["month"],
                 "gross": amount(decimal_value(r["gross_income"]))} for r in incomes]
        notes.append("Gross income is reported in PHP (Philippine peso), as approved by the project owner. It is not profit or net income.")
        income_notes = ["Only recorded gross-income entries are summed. Missing income does not become zero and incomplete monthly coverage is not a complete-quarter estimate."]
        if month is not None:
            income_notes.append(f"{sum(r['period_type'] == 'quarterly' for r in data['income'])} quarterly entries excluded: quarterly income cannot be allocated to the selected month.")
        total = sum((decimal_value(r["gross_income"]) for r in incomes), Decimal("0.00")) if incomes else None
        sections.append(section("income", "Recorded gross income (PHP)", [("association", "Association", "text"),
            ("project", "Project", "text"), ("product", "Product", "text"), ("year", "Year", "integer"), ("quarter", "Quarter", "integer"),
            ("frequency", "Income frequency", "text"), ("month", "Month (monthly only)", "integer"), ("gross", "Recorded gross income (PHP)", "decimal")], rows,
            [measure("Recorded income observations", len(incomes)), measure("Sum of recorded gross income (PHP)", amount(total)),
             measure("Recorded zero income observations", sum(decimal_value(r["gross_income"]) == 0 for r in incomes)),
             measure("Production observations without selected income", len(set(production) - {r["production_id"] for r in incomes}))], income_notes))
        groups = defaultdict(list)
        for row in rows:
            groups[(row["year"], row["quarter"], row["frequency"])].append(decimal_value(row["gross"]))
        sections.append(section("income_periods", "Recorded income by period and frequency", [("period", "Period / frequency", "text"),
            ("observations", "Recorded entries", "integer"), ("gross", "Sum of recorded gross income (PHP)", "decimal")],
            [{"period": f"{year} Q{quarter} / {frequency}", "observations": len(values), "gross": amount(sum(values, Decimal("0.00")))}
             for (year, quarter, frequency), values in sorted(groups.items())],
            chart={"title": "Observed gross income; coverage may differ", "label_key": "period", "value_key": "gross", "unit": "PHP"}))

    if all_reports or report == "activities":
        rows = [{"association": association_name(r["association_id"]), "activity": r["title"], "date": r["activity_date"],
                 "component": r["component"], "status": r["status"], "lifecycle": "Archived" if r["archived_at"] else "Unarchived"} for r in data["activities"]]
        sections.append(section("activities", "Recorded program activities", [("association", "Association", "text"), ("activity", "Activity", "text"),
            ("date", "Activity date", "date"), ("component", "Component", "text"), ("status", "Recorded status", "text"), ("lifecycle", "Archive state", "text")], rows,
            [measure("Recorded program activities", len(rows))], ["Unlinked program activities are available only to system administrators without association/municipality filters."]))
        sections.append(distribution("activity_component", "Program activities by component", data["activities"], "component", "Component"))
        visits = Counter(r["record_id"] for r in data["visits"])
        rows = [{"association": association_name(projects[r["project_id"]]["association_id"]), "project": projects[r["project_id"]]["title"],
                 "year": r["year"], "quarter": r["quarter"], "status": r["project_status"], "visit_reports": visits[r["id"]]} for r in data["monitoring"]]
        sections.append(section("monitoring", "Quarterly monitoring periods", [("association", "Association", "text"), ("project", "Project", "text"),
            ("year", "Year", "integer"), ("quarter", "Quarter", "integer"), ("status", "Historically recorded status", "text"),
            ("visit_reports", "Visit-report rows", "integer")], rows,
            [measure("Monitoring records", len(monitoring)), measure("Visit-report rows", len(data["visits"])),
             measure("Legacy imported visit-report rows", sum(truth(r["is_legacy_import"]) for r in data["visits"])),
             measure("Selected projects without monitoring", len(set(projects) - {r["project_id"] for r in data["monitoring"]}))],
            ["A monitoring record is a quarterly container, not a physical visit. Visit-report rows include legacy imports and do not establish a verified physical-visit total.",
             "Period filters select the parent monitoring year/quarter; all visit reports attached to those parents are included."]))
        visit_rows = []
        for row in data["visits"]:
            record = monitoring[row["record_id"]]
            project = projects[record["project_id"]]
            visit_rows.append({"association": association_name(project["association_id"]), "project": project["title"], "year": record["year"],
                "quarter": record["quarter"], "date": row["monitoring_date"], "source": "Legacy import" if truth(row["is_legacy_import"]) else "Recorded visit report"})
        sections.append(section("visits", "Visit-report observations", [("association", "Association", "text"), ("project", "Project", "text"),
            ("year", "Parent year", "integer"), ("quarter", "Parent quarter", "integer"), ("date", "Recorded visit date", "date"), ("source", "Source", "text")], visit_rows))
        sections.append(distribution("monitoring_status", "Historically recorded monitoring statuses", data["monitoring"], "project_status", "Recorded status"))

    meaningful = {"associations": data["associations"], "members": data["members"], "projects": data["projects"], "trainings": data["trainings"],
                  "production": data["production"], "income": data["income"], "activities": data["activities"] + data["monitoring"]}
    empty = not any(meaningful.values()) if all_reports else not meaningful[report]
    if report == "income" and meta["filters"].get("month"):
        empty = not incomes
    if geography_section is not None:
        sections.append(geography_section)
    return {"version": VERSION, "title": TITLES[report], "meta": meta, "empty": empty, "notes": notes, "sections": sections}
