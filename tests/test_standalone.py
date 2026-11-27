"""Offline build and extraction regressions; never loads or repairs a Suite."""
from pathlib import Path
import hashlib
import json
import os
import re
import shutil
import subprocess
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


class StandaloneBuildTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='recovery-standalone-test-')
        self.addCleanup(self.temp.cleanup)
        self.work = Path(self.temp.name)
        self.project = self.work / 'independent-source'
        self.project.mkdir()
        for name in ['stub', 'recovery-src', 'dev']:
            shutil.copytree(ROOT / name, self.project / name, ignore=shutil.ignore_patterns('__pycache__'))
        self.output = self.work / 'local-output'
        self.env = dict(os.environ, RECOVERY_DIST_DIR=str(self.output))
        self.version = re.search(r"define\('RECOVERY_PACKAGE_VERSION',\s*'([^']+)'\)",
                                 (self.project / 'recovery-src/version.php').read_text())[1]
        self.original = {p.relative_to(self.project).as_posix(): digest(p)
                         for p in self.project.rglob('*') if p.is_file()}

    def command(self, script, *args, env=None):
        return subprocess.run(['bash', str(self.project / 'dev' / script), *args],
                              cwd=self.work, env=env or self.env, capture_output=True, text=True)

    def build(self):
        result = self.command('build-release.sh')
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        return self.output / ('recovery-' + self.version + '.tar.gz')

    def fake_publish_tools(self):
        folder = self.work / 'fake-bin'
        folder.mkdir()
        trace = self.work / 'unexpected-external-command'
        for name in ['git', 'gh']:
            path = folder / name
            path.write_text('#!/bin/sh\necho invoked >> "$RECOVERY_TEST_TRACE"\nexit 99\n')
            path.chmod(0o755)
        return dict(self.env, PATH=str(folder) + os.pathsep + self.env.get('PATH', ''),
                    RECOVERY_TEST_TRACE=str(trace)), trace

    def test_build_without_swpm_and_without_source_version_changes(self):
        artifact = self.build()
        self.assertTrue(artifact.is_file())
        self.assertTrue((self.output / 'plugin-recovery-tool.php').is_file())
        for name, expected in self.original.items():
            self.assertEqual(expected, digest(self.project / name), name)
        with tarfile.open(artifact) as archive:
            names = archive.getnames()
            self.assertIn('app.php', names)
            self.assertIn('bootstrap.php', names)
            self.assertIn('lib/Recovery/Modes/acp_repair.php', names)
            self.assertTrue(all(not n.startswith(('/', './')) and '..' not in Path(n).parts for n in names))
            manifest = json.loads(archive.extractfile('manifest.json').read())
            self.assertEqual(self.version, manifest['version'])
            version_source = archive.extractfile('version.php').read().decode()
            self.assertIn("RECOVERY_PACKAGE_VERSION', '" + self.version, version_source)
        stub = (self.output / 'plugin-recovery-tool.php').read_text()
        pattern = r"^define\('RECOVERY_STUB_INTEGRITY_HASH',\s*'([^']+)'\);"
        match = re.search(pattern, stub, flags=re.MULTILINE)
        self.assertIsNotNone(match)
        canonical = re.sub(pattern, "define('RECOVERY_STUB_INTEGRITY_HASH', '" + '0' * 64 + "');",
                           stub, count=1, flags=re.MULTILINE)
        self.assertEqual(match[1], hashlib.sha256(canonical.encode()).hexdigest())
        syntax = subprocess.run(['php', '-l', str(self.output / 'plugin-recovery-tool.php')], capture_output=True, text=True)
        self.assertEqual(0, syntax.returncode, syntax.stderr + syntax.stdout)

    def test_reproducible_archive_and_stub(self):
        archive = self.build()
        before = digest(archive), digest(self.output / 'plugin-recovery-tool.php')
        os.utime(self.project / 'recovery-src/app.php', (1, 1))
        self.build()
        self.assertEqual(before, (digest(archive), digest(self.output / 'plugin-recovery-tool.php')))

    def test_default_publisher_performs_no_git_gh_or_build(self):
        archive = self.build()
        before = archive.stat().st_mtime_ns, digest(archive)
        env, trace = self.fake_publish_tools()
        result = self.command('publish-github-release.sh', self.version, env=env)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        self.assertIn('PLAN ONLY', result.stdout)
        self.assertFalse(trace.exists())
        self.assertEqual(before, (archive.stat().st_mtime_ns, digest(archive)))

    def test_explicit_publisher_refuses_unreviewed_nonrepository(self):
        self.build()
        env, trace = self.fake_publish_tools()
        result = self.command('publish-github-release.sh', '--publish', self.version, env=env)
        self.assertNotEqual(0, result.returncode)
        self.assertIn('standalone Recovery repository', result.stderr)
        self.assertFalse(trace.exists())

    def test_publisher_rejects_changed_artifact(self):
        self.build()
        with (self.output / 'plugin-recovery-tool.php').open('a') as stream:
            stream.write('\n// changed after build\n')
        result = self.command('publish-github-release.sh', self.version)
        self.assertNotEqual(0, result.returncode)
        self.assertIn('artifact changed', result.stderr)

    def test_publisher_rejects_changed_source(self):
        self.build()
        with (self.project / 'recovery-src/app.php').open('a') as stream:
            stream.write('\n// source changed after build\n')
        result = self.command('publish-github-release.sh', self.version)
        self.assertNotEqual(0, result.returncode)
        self.assertIn('Source changed after build', result.stderr)

    def test_publisher_rejects_changed_builder(self):
        self.build()
        with (self.project / 'dev/build-release.sh').open('a') as stream:
            stream.write('\n# Builder changed after build\n')
        result = self.command('publish-github-release.sh', self.version)
        self.assertNotEqual(0, result.returncode)
        self.assertIn('Builder changed after build', result.stderr)

    def test_invalid_version_is_rejected_before_output(self):
        result = self.command('build-release.sh', "2.7.48'; touch unexpected")
        self.assertEqual(2, result.returncode)
        self.assertFalse(self.output.exists())

    def test_source_output_and_source_secrets_are_rejected(self):
        env = dict(self.env, RECOVERY_DIST_DIR=str(self.project / 'recovery-src'))
        result = self.command('build-release.sh', env=env)
        self.assertNotEqual(0, result.returncode)
        self.assertIn('must not overwrite source', result.stderr)
        (self.project / 'recovery-src/.env').write_text('SYNTHETIC_TEST_ONLY=1\n')
        result = self.command('build-release.sh')
        self.assertNotEqual(0, result.returncode)
        self.assertIn('Runtime or secret file', result.stderr)
        self.assertFalse(self.output.exists())

    def test_missing_php_is_blocked_not_pass(self):
        result = self.command('validate-php-syntax.sh', env=dict(self.env, PHP_BIN='/does-not-exist/qa-php'))
        self.assertEqual(2, result.returncode)
        self.assertIn('BLOCKED', result.stderr)

    def test_deploy_requires_explicit_apply_target_and_prebuilt_files(self):
        result = self.command('deploy-recovery.sh')
        self.assertEqual(2, result.returncode)
        target = self.work / 'synthetic-target'
        target.mkdir()
        (target / 'global.php').write_text('<?php // Synthetic target marker; never execute a Suite.\n')
        result = self.command('deploy-recovery.sh', '--apply', str(target), self.version)
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(['global.php'], sorted(p.name for p in target.iterdir()))

    def test_deploy_places_package_only_in_owned_subdirectory(self):
        self.build()
        target = self.work / 'synthetic-target'
        target.mkdir()
        (target / 'global.php').write_text('<?php // Synthetic target marker; never execute a Suite.\n')
        result = self.command('deploy-recovery.sh', '--apply', str(target), self.version)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        self.assertEqual(['global.php', 'plugin-recovery-tool.php', 'recovery-tool'], sorted(p.name for p in target.iterdir()))
        self.assertTrue((target / 'recovery-tool/app.php').is_file())
        self.assertFalse((target / 'app.php').exists())
        repeated = self.command('deploy-recovery.sh', '--apply', str(target), self.version)
        self.assertNotEqual(0, repeated.returncode)
        self.assertIn('no automatic overwrite', repeated.stderr)


class SourceHistoryTests(unittest.TestCase):
    def test_import_preserves_both_histories_and_all_source_bytes(self):
        manifest = json.loads((ROOT / 'docs/source-import/manifest.json').read_text())
        for ancestor in [manifest['existing_public_master'], manifest['imported_history_head']]:
            result = subprocess.run(['git', '-C', str(ROOT), 'merge-base', '--is-ancestor', ancestor, 'HEAD'])
            self.assertEqual(0, result.returncode)
        merge_rows = subprocess.check_output(['git', '-C', str(ROOT), 'log', '--all', '--merges', '--format=%H %P'], text=True).splitlines()
        import_merges = [row.split()[0] for row in merge_rows if set(row.split()[1:]) ==
                         {manifest['existing_public_master'], manifest['imported_history_head']}]
        self.assertEqual(1, len(import_merges))
        for entry in manifest['files']:
            content = subprocess.check_output(['git', '-C', str(ROOT), 'show',
                                               manifest['imported_history_head'] + ':' + entry['source']])
            self.assertEqual(entry['sha256'], hashlib.sha256(content).hexdigest(), entry['source'])
            imported = subprocess.check_output(['git', '-C', str(ROOT), 'show',
                                                import_merges[0] + ':' + entry['destination']])
            self.assertEqual(entry['sha256'], hashlib.sha256(imported).hexdigest(), entry['destination'])
        self.assertEqual(53, len(manifest['files']))
        self.assertEqual(3, len(manifest['history_mapping']))
        self.assertEqual(manifest['source_head'], manifest['history_mapping'][-1]['manager_commit'])


if __name__ == '__main__':
    unittest.main()
