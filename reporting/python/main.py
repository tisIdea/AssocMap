"""One bounded stdin request, one stdout response, no network/database calls."""

import json
from pathlib import Path
import sys

# Isolated Python excludes the script directory; add only this trusted source folder.
sys.path.insert(0, str(Path(__file__).resolve().parent))

from analytics import calculate
from contracts import ContractError, SCHEMAS, VERSION, validate

MAX_JSON_BYTES = 8 * 1024 * 1024
MAX_ARTIFACT_BYTES = 12 * 1024 * 1024


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ContractError("duplicate_key")
        result[key] = value
    return result


def reject_constant(_):
    raise ContractError("nonfinite")


def main():
    artifact = None
    stage = "input_contract"
    try:
        raw = sys.stdin.buffer.read(MAX_JSON_BYTES + 1)
        if len(raw) > MAX_JSON_BYTES:
            raise ContractError("input_limit")
        payload = validate(json.loads(raw.decode("utf-8"), object_pairs_hook=unique_object,
                                      parse_constant=reject_constant))
        stage = "calculation"
        result = calculate(payload)
        response = {"ok": True, "version": VERSION, "result": result}
        if payload["action"] != "analytics":
            stage = "export"
            import exporters
            artifact = Path.cwd() / ("report." + payload["action"])
            if artifact.exists() or artifact.is_symlink():
                raise ContractError("artifact_exists")
            {"pdf": exporters.pdf, "xlsx": exporters.xlsx}[payload["action"]](result, artifact)
            size = artifact.stat().st_size
            if not 0 < size <= MAX_ARTIFACT_BYTES:
                raise ContractError("artifact_limit")
            response["artifact"] = {"name": artifact.name, "size": size}
        stage = "serialization"
        encoded = json.dumps(response, ensure_ascii=False, allow_nan=False, separators=(",", ":")).encode("utf-8")
        if len(encoded) > MAX_JSON_BYTES:
            raise ContractError("output_limit")
        sys.stdout.buffer.write(encoded)
        return 0
    except Exception as error:
        # Never expose tracebacks, private dataset values or local paths.
        if artifact is not None and artifact.is_file() and not artifact.is_symlink():
            try:
                artifact.unlink(missing_ok=True)
            except OSError:
                pass
        code = {"input_contract": "input_contract_failed", "calculation": "calculation_failed",
                "export": "export_failed", "serialization": "serialization_failed"}[stage]
        if isinstance(error, ContractError) and str(error) in ("input_limit", "output_limit", "artifact_limit"):
            code, stage = str(error), "resource_limit"
        elif isinstance(error, ImportError):
            code = "dependency_unavailable"
        elif isinstance(error, ValueError) and str(error) in ("font_unavailable", "font_missing_glyph", "xlsx_numeric_precision_limit"):
            code = str(error)
        diagnostic = {"code": code, "stage": stage}
        if isinstance(error, ContractError) and error.dataset in SCHEMAS:
            diagnostic["dataset"] = error.dataset
            if error.field in SCHEMAS[error.dataset]:
                diagnostic["field"] = error.field
        sys.stdout.write(json.dumps({"ok": False, "version": VERSION, "error": diagnostic}))
        return 1


if __name__ == "__main__":
    sys.exit(main())
