import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).with_name("prepare.py").resolve()

class PreviewPreparationTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        source = self.root / "akaiv-saas"
        source.mkdir()
        (source / ".env").write_text("APP_KEY=existing-do-not-copy")
        (source / "storage").mkdir()
        (source / "storage" / "record.txt").write_text("existing record")
        (source / "artisan").write_text("sample source")
        self.env = {**os.environ, "CODESPACES": "", "AKAIV_PREVIEW_CI": "1"}

    def run_setup(self, **overrides):
        return subprocess.run(["python3", str(SCRIPT), str(self.root)],
                              env={**self.env, **overrides}, capture_output=True)

    def test_preserves_keys_and_excludes_existing_records(self):
        self.assertEqual(self.run_setup().returncode, 0)
        state = self.root / ".preview"
        before = (state / "app/.env").read_bytes()
        logins = (state / "logins.json").read_bytes()
        self.assertNotIn(b"existing-do-not-copy", before)
        self.assertFalse((state / "app/storage/record.txt").exists())
        self.assertEqual(self.run_setup(DB_URL="postgresql://external/production").returncode, 0)
        self.assertEqual((state / "app/.env").read_bytes(), before)
        self.assertEqual((state / "logins.json").read_bytes(), logins)
        self.assertIn(b"DB_URL=null", before)
        self.assertEqual(len(json.loads(logins)), 2)

    def test_refuses_missing_key_or_logins(self):
        self.assertEqual(self.run_setup().returncode, 0)
        path = self.root / ".preview/app/.env"
        path.unlink()
        self.assertNotEqual(self.run_setup().returncode, 0)

    def test_refuses_nonpreview_database(self):
        self.assertEqual(self.run_setup().returncode, 0)
        path = self.root / ".preview/app/.env"
        path.write_text(path.read_text().replace("akaiv_preview_pilot_test", "production"))
        before = path.read_bytes()
        self.assertNotEqual(self.run_setup().returncode, 0)
        self.assertEqual(path.read_bytes(), before)

    def test_requires_disposable_opt_in(self):
        self.assertNotEqual(self.run_setup(AKAIV_PREVIEW_CI="").returncode, 0)
        self.assertFalse((self.root / ".preview").exists())

if __name__ == "__main__":
    unittest.main()
