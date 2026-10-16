"""Exercise the real bounded CLI using synthetic payloads and private temp directories."""

from copy import deepcopy
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0,str(Path(__file__).resolve().parent))
from test_analytics import fixture, uid

ENTRY=Path(__file__).resolve().parents[1]/"main.py"


class TransportTest(unittest.TestCase):
    def invoke(self,payload,*,raw=None,extra_env=None):
        with tempfile.TemporaryDirectory(prefix="assocmap-report-test-") as directory:
            environment={key:os.environ[key] for key in ("SystemRoot","SYSTEMROOT","WINDIR") if key in os.environ}
            environment.update({key:directory for key in ("TEMP","TMP","TMPDIR")})
            environment.update(extra_env or {})
            process=subprocess.run([sys.executable,"-I","-B","-X","utf8",str(ENTRY)],input=raw if raw is not None else json.dumps(payload).encode("utf-8"),
                cwd=directory,env=environment,capture_output=True,timeout=20,check=False)
            response=json.loads(process.stdout)
            artifacts={path.name:path.read_bytes() for path in Path(directory).glob("report.*")}
            self.assertEqual(b"",process.stderr)
            return process.returncode,response,artifacts

    def test_valid_ongoing_activity_and_monthly_context_survive_the_actual_cli(self):
        payload=fixture()
        payload["datasets"]["activities"]=[{"id":uid(50),"association_id":uid(1),"title":"Synthetic ongoing activity",
            "program_year":2026,"activity_date":"2026-01-01","status":"ongoing","archived_at":None,"component":"Synthetic component"}]
        payload["datasets"]["monthly"]=[{"production_id":uid(7),"month":1,"activity_count":0,"total_quantity":"0.00"}]
        code,response,artifacts=self.invoke(payload)
        self.assertEqual(0,code); self.assertTrue(response["ok"]); self.assertFalse(artifacts)
        for section in response["result"]["sections"]:
            for row in section["rows"]:
                self.assertEqual({column["key"] for column in section["columns"]},set(row))

    def test_invalid_json_duplicate_keys_and_unsupported_actions_fail_without_input_echo(self):
        payload=fixture()
        raw_payload=json.dumps(payload).encode("utf-8")
        duplicate=raw_payload.replace(b'{"version":',b'{"version":"duplicate", "version":',1)
        for raw in (b'{"private_value":"synthetic-withheld",',duplicate):
            with self.subTest(raw=raw[:15]):
                code,response,artifacts=self.invoke(None,raw=raw)
                self.assertEqual(1,code); self.assertEqual("input_contract_failed",response["error"]["code"])
                self.assertNotIn("synthetic-withheld",json.dumps(response)); self.assertFalse(artifacts)
        payload["action"]="csv"
        self.assertEqual(1,self.invoke(payload)[0])

    def test_input_limit_is_enforced_before_parsing(self):
        code,response,artifacts=self.invoke(None,raw=b" "*(8*1024*1024+1))
        self.assertEqual(1,code); self.assertEqual("input_limit",response["error"]["code"]); self.assertFalse(artifacts)

    def test_invalid_activity_status_identifies_only_the_allowlisted_contract_field(self):
        payload=fixture("activities")
        payload["datasets"]["activities"]=[{"id":uid(50),"association_id":uid(1),"title":"Synthetic invalid status",
            "program_year":2026,"activity_date":"2026-01-01","status":"suspended","archived_at":None,"component":"Synthetic component"}]
        code,response,artifacts=self.invoke(payload)
        self.assertEqual(1,code)
        self.assertEqual({"code":"input_contract_failed","stage":"input_contract","dataset":"activities","field":"status"},response["error"])
        self.assertFalse(artifacts)

    def test_empty_pdf_and_xlsx_are_real_files_with_the_same_empty_result(self):
        for action,signature in (("pdf",b"%PDF-"),("xlsx",b"PK\x03\x04")):
            with self.subTest(action=action):
                payload=fixture("income"); payload["action"]=action
                code,response,artifacts=self.invoke(payload)
                self.assertEqual(0,code); self.assertTrue(response["result"]["empty"])
                artifact=artifacts["report."+action]
                self.assertTrue(artifact.startswith(signature)); self.assertEqual(len(artifact),response["artifact"]["size"])

    def test_pdf_font_configuration_and_glyph_failures_remove_partial_artifacts(self):
        payload=fixture("projects"); payload["action"]="pdf"
        code,response,artifacts=self.invoke(payload,extra_env={"ASSOCMAP_REPORT_FONT_REGULAR":str(ENTRY.parent/"missing-synthetic-font.ttf")})
        self.assertEqual(1,code); self.assertEqual("font_unavailable",response["error"]["code"]); self.assertFalse(artifacts)
        payload["datasets"]["projects"][0]["title"]="Synthetic \u5408"
        code,response,artifacts=self.invoke(payload)
        self.assertEqual(1,code); self.assertEqual("font_missing_glyph",response["error"]["code"]); self.assertFalse(artifacts)

    def test_xlsx_refuses_a_recorded_sum_above_safe_numeric_precision(self):
        payload=fixture("income"); payload["action"]="xlsx"
        template=payload["datasets"]["production"][0]
        payload["datasets"]["production"]=[]
        for index in range(101):
            production=deepcopy(template); production.update(id=uid(1000+index),project_product_id=uid(2000+index))
            payload["datasets"]["production"].append(production)
            payload["datasets"]["income"].append({"id":uid(3000+index),"production_id":production["id"],
                "period_type":"quarterly","month":None,"gross_income":"999999999999.99"})
        code,response,artifacts=self.invoke(payload)
        self.assertEqual(1,code); self.assertEqual("xlsx_numeric_precision_limit",response["error"]["code"]); self.assertFalse(artifacts)


if __name__=="__main__":
    unittest.main()
