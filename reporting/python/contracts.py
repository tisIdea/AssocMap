"""Versioned, bounded JSON input. No database or identity credentials."""

from datetime import date, datetime
from decimal import Decimal
import re
from uuid import UUID

VERSION = "assocmap.reports.v1"
TITLES = {
    "comprehensive": "Comprehensive Report",
    "associations": "Association Distribution",
    "members": "Member Summary",
    "projects": "Project Portfolio",
    "trainings": "Training Participation",
    "production": "Production Performance",
    "income": "Gross Income",
    "activities": "Activities and Monitoring",
}

SCHEMAS = {
    "associations": {"id": "uuid", "name": "text", "status": "text", "archived_at": "timestamp?",
                     "municipality_id": "uuid", "municipality": "text", "barangay_id": "uuid", "barangay": "text"},
    "members": {"id": "uuid", "association_id": "uuid", "status": "text", "archived_at": "timestamp?"},
    "classifications": {"member_id": "uuid", "classification_id": "int", "classification": "text"},
    "projects": {"id": "uuid", "association_id": "uuid", "title": "text", "program_year": "int",
                 "status": "text", "archived_at": "timestamp?", "component": "text", "category": "text"},
    "trainings": {"id": "uuid", "association_id": "uuid", "title": "text", "program_year": "int",
                  "start_date": "date", "end_date": "date?", "status": "text", "archived_at": "timestamp?", "component": "text"},
    "participants": {"training_id": "uuid", "member_id": "uuid", "attendance_status": "text"},
    "monitoring": {"id": "uuid", "project_id": "uuid", "year": "int", "quarter": "int", "monitoring_date": "date", "project_status": "text"},
    "production": {"id": "uuid", "monitoring_record_id": "uuid", "project_product_id": "uuid", "product": "text",
                   "target_output": "decimal?", "actual_output": "decimal?", "unit": "text"},
    "monthly": {"production_id": "uuid", "month": "int", "activity_count": "int", "total_quantity": "decimal"},
    "income": {"id": "uuid", "production_id": "uuid", "period_type": "text", "month": "int?", "gross_income": "decimal"},
    "activities": {"id": "uuid", "association_id": "uuid?", "title": "text", "program_year": "int", "activity_date": "date",
                   "status": "text", "archived_at": "timestamp?", "component": "text"},
    "visits": {"id": "uuid", "record_id": "uuid", "monitoring_date": "date", "is_legacy_import": "bool"},
}


class ContractError(ValueError):
    def __init__(self, reason, *, dataset=None, field=None):
        super().__init__(reason)
        self.dataset = dataset
        self.field = field


def require(condition, *, dataset=None, field=None):
    if not condition:
        raise ContractError("invalid_contract", dataset=dataset, field=field)


def decimal_value(value):
    require(isinstance(value, str) and re.fullmatch(r"\d{1,12}(?:\.\d{1,2})?", value) is not None)
    return Decimal(value)


def field(value, kind):
    if kind.endswith("?"):
        if value is None:
            return
        kind = kind[:-1]
    if kind == "text":
        require(isinstance(value, str) and 0 < len(value) <= 1000)
        require(not any(ord(char) < 32 and char not in "\t\n\r" for char in value))
    elif kind == "uuid":
        require(isinstance(value, str) and str(UUID(value)) == value.lower())
    elif kind == "int":
        require(type(value) is int and 0 <= value <= 2147483647)
    elif kind == "decimal":
        decimal_value(value)
    elif kind == "date":
        require(isinstance(value, str) and date.fromisoformat(value).isoformat() == value)
    elif kind == "timestamp":
        require(isinstance(value, str) and len(value) <= 60)
        datetime.fromisoformat(value.replace("Z", "+00:00"))
    elif kind == "bool":
        require(type(value) is bool or (type(value) is int and value in (0, 1))
                or (isinstance(value, str) and value in ("t", "f", "0", "1")))
    else:
        raise ContractError("invalid_contract")


def truth(value):
    return value is True or value in (1, "1", "t")


def validate(payload):
    require(type(payload) is dict and set(payload) == {"version", "action", "meta", "datasets"})
    require(payload["version"] == VERSION and payload["action"] in ("analytics", "pdf", "xlsx"))
    meta = payload["meta"]
    require(type(meta) is dict and set(meta) == {"report_type", "filters", "generated_at", "timezone", "scope", "scope_basis", "currency"})
    require(meta["report_type"] in TITLES and meta["timezone"] == "Asia/Manila" and meta["currency"] == "PHP")
    field(meta["generated_at"], "timestamp")
    require(datetime.fromisoformat(meta["generated_at"]).utcoffset().total_seconds() == 28800)
    field(meta["scope_basis"], "text")
    require(type(meta["scope"]) is list and len(meta["scope"]) <= 1000)
    for name in meta["scope"]:
        field(name, "text")
    filters = meta["filters"]
    require(type(filters) is dict and filters.get("report_type") == meta["report_type"])
    allowed = {"report_type", "association_id", "municipality_id", "project_id", "product_id", "component_id", "year", "program_year", "quarter", "month"}
    require(set(filters) <= allowed)
    for key, value in filters.items():
        if key == "report_type":
            continue
        field(value, "uuid" if key.endswith("_id") and key != "component_id" else "int")
        if key in ("year", "program_year"):
            require(2000 <= value <= 2100)
        if key == "quarter":
            require(1 <= value <= 4)
        if key == "month":
            require(1 <= value <= 12)
    data = payload["datasets"]
    require(type(data) is dict and set(data) == set(SCHEMAS))
    total = 0
    for name, schema in SCHEMAS.items():
        rows = data[name]
        require(type(rows) is list and len(rows) <= 5000, dataset=name)
        total += len(rows)
        seen = set()
        for row in rows:
            require(type(row) is dict and set(row) == set(schema), dataset=name)
            for key, kind in schema.items():
                try:
                    field(row[key], kind)
                except (ValueError, TypeError, AttributeError, OverflowError):
                    raise ContractError("invalid_contract", dataset=name, field=key) from None
            identity = row.get("id")
            if name in ("participants", "classifications", "monthly"):
                keys = {"participants": ("training_id", "member_id"), "classifications": ("member_id", "classification_id"), "monthly": ("production_id", "month")}[name]
                identity = tuple(row[key] for key in keys)
            require(identity not in seen, dataset=name)
            seen.add(identity)
    require(total <= 20000)
    require(meta["scope"] == [row["name"] for row in data["associations"]])
    municipalities, barangays = {}, {}
    for row in data["associations"]:
        require(municipalities.setdefault(row["municipality_id"], row["municipality"]) == row["municipality"], dataset="associations", field="municipality_id")
        geography = (row["municipality_id"], row["barangay"])
        require(barangays.setdefault(row["barangay_id"], geography) == geography, dataset="associations", field="barangay_id")
    for key in ("association_id", "municipality_id"):
        if key in filters:
            require(all(row["id" if key == "association_id" else key] == filters[key] for row in data["associations"]))
    for name, keys in {"monitoring": ("project_id", "year", "quarter"),
                       "production": ("monitoring_record_id", "project_product_id")}.items():
        identities = [tuple(row[key] for key in keys) for row in data[name]]
        require(len(identities) == len(set(identities)), dataset=name)
    indexes = {name: {row["id"]: row for row in rows} for name, rows in data.items() if "id" in SCHEMAS[name]}
    for name in ("members", "projects", "trainings", "activities"):
        for row in data[name]:
            require(row["association_id"] in indexes["associations"] or (name == "activities" and row["association_id"] is None), dataset=name, field="association_id")
    for name, key, parent in [("classifications", "member_id", "members"), ("participants", "training_id", "trainings"),
                              ("monitoring", "project_id", "projects"), ("production", "monitoring_record_id", "monitoring"),
                              ("monthly", "production_id", "production"), ("income", "production_id", "production"), ("visits", "record_id", "monitoring")]:
        for row in data[name]:
            require(row[key] in indexes[parent], dataset=name, field=key)
    for row in data["monitoring"]:
        require(2000 <= row["year"] <= 2100 and 1 <= row["quarter"] <= 4, dataset="monitoring")
    statuses = {
        "associations": ("active", "inactive"), "members": ("active", "inactive"),
        "projects": ("planned", "ongoing", "completed", "terminated", "suspended", "cancelled"),
        "trainings": ("scheduled", "completed", "cancelled"), "activities": ("planned", "ongoing", "completed", "cancelled"),
    }
    for name, allowed_statuses in statuses.items():
        require(all(row["status"] in allowed_statuses for row in data[name]), dataset=name, field="status")
    require(all(row["project_status"] in statuses["projects"] for row in data["monitoring"]), dataset="monitoring", field="project_status")
    frequencies = {}
    income_keys = set()
    for row in data["income"]:
        require(row["period_type"] in ("monthly", "quarterly"), dataset="income", field="period_type")
        require((row["month"] is None) == (row["period_type"] == "quarterly"), dataset="income", field="month")
        previous = frequencies.setdefault(row["production_id"], row["period_type"])
        require(previous == row["period_type"], dataset="income", field="period_type")
        key = (row["production_id"], row["month"])
        require(key not in income_keys, dataset="income")
        income_keys.add(key)
    for name in ("monthly", "income"):
        for row in data[name]:
            if row["month"] is not None:
                production = indexes["production"][row["production_id"]]
                quarter = indexes["monitoring"][production["monitoring_record_id"]]["quarter"]
                require(1 <= row["month"] <= 12 and (row["month"] - 1) // 3 + 1 == quarter, dataset=name, field="month")
    require(all(row["attendance_status"] in ("present", "absent") for row in data["participants"]), dataset="participants", field="attendance_status")
    return payload
