import json
import sys
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import system_watcher as watcher

NOW = datetime(2026, 10, 27, 13, 21, tzinfo=timezone.utc)
OPENED = datetime(2026, 10, 27, 13, 1, tzinfo=timezone.utc)


def problem(key="unit:pi-now-watch", title="pi-now-watch is not active", alertable=True, since=OPENED):
    return {
        "key": key,
        "severity": "critical",
        "title": title,
        "detail": "inactive",
        "since": since.isoformat(),
        "alertable": alertable,
    }


def notice(event_id, body):
    return {"id": event_id, "kind": "notice", "title": body.splitlines()[0], "body": body, "occurred_at": NOW.isoformat()}


def settled(alerted=None, last_event_id=0):
    return {"alerted": alerted or {}, "last_event_id": last_event_id, "baseline_done": True}


def local(moment):
    return moment.astimezone().strftime("%H:%M")


class FakeClient:
    def __init__(self, problems=None, events=None, error=None):
        self.problems = problems or []
        self.events = events or []
        self.error = error
        self.calls = []

    def call_tool(self, name, arguments):
        self.calls.append((name, arguments))

        if self.error:
            raise self.error

        if name == "system_problems":
            return {"problems": self.problems}

        return {"events": self.events}


class DecideTest(unittest.TestCase):
    def test_first_run_is_a_silent_baseline(self):
        message, state = watcher.decide(
            watcher.default_state(),
            [problem(), problem("disk:/", "Disk / 99%", alertable=False)],
            [notice(7, "Download completed: X (700 MB)")],
            NOW,
        )

        self.assertEqual("", message)
        self.assertTrue(state["baseline_done"])
        self.assertEqual(["unit:pi-now-watch"], list(state["alerted"]))
        self.assertEqual(7, state["last_event_id"])

    def test_new_alertable_key_posts_one_critical_line(self):
        message, state = watcher.decide(settled(), [problem()], [], NOW)

        self.assertEqual(
            f"CRITICAL pi-now-watch is not active (since {local(OPENED)})",
            message,
        )
        self.assertIn("unit:pi-now-watch", state["alerted"])

    def test_same_key_next_run_is_silent(self):
        _, state = watcher.decide(settled(), [problem()], [], NOW)

        message, again = watcher.decide(state, [problem()], [], NOW + timedelta(minutes=1))

        self.assertEqual("", message)
        self.assertEqual(state["alerted"], again["alerted"])

    def test_key_gone_posts_one_resolved_line_with_its_duration(self):
        _, state = watcher.decide(settled(), [problem()], [], NOW - timedelta(minutes=1))

        message, after = watcher.decide(state, [], [], NOW)

        self.assertEqual("RESOLVED pi-now-watch is not active (lasted 20 min)", message)
        self.assertEqual({}, after["alerted"])

    def test_critical_that_is_not_alertable_yet_is_silent(self):
        message, state = watcher.decide(settled(), [problem(alertable=False)], [], NOW)

        self.assertEqual("", message)
        self.assertEqual({}, state["alerted"])

    def test_problem_dropping_to_not_alertable_resolves(self):
        _, state = watcher.decide(settled(), [problem()], [], NOW)

        message, _ = watcher.decide(state, [problem(alertable=False)], [], NOW)

        self.assertTrue(message.startswith("RESOLVED pi-now-watch is not active"))

    def test_two_notices_post_both_bodies_and_advance_the_cursor(self):
        events = [
            notice(8, "Download completed: A (700 MB)"),
            notice(9, "Backup finished: B\nFiles 1:\n• Item (1)"),
        ]

        message, state = watcher.decide(settled(last_event_id=7), [], events, NOW)

        self.assertEqual(
            "Download completed: A (700 MB)\n\nBackup finished: B\nFiles 1:\n• Item (1)",
            message,
        )
        self.assertEqual(9, state["last_event_id"])

    def test_nothing_new_is_empty_output(self):
        message, state = watcher.decide(settled(last_event_id=7), [], [], NOW)

        self.assertEqual("", message)
        self.assertEqual(7, state["last_event_id"])

    def test_items_are_joined_by_a_blank_line(self):
        _, state = watcher.decide(settled(), [problem()], [], NOW - timedelta(minutes=1))

        message, _ = watcher.decide(
            state,
            [problem("unit:cron", "cron is not active")],
            [notice(3, "Download completed: A (1 MB)")],
            NOW,
        )

        blocks = message.split("\n\n")

        self.assertEqual(3, len(blocks))
        self.assertTrue(blocks[0].startswith("CRITICAL cron is not active"))
        self.assertTrue(blocks[1].startswith("RESOLVED pi-now-watch"))
        self.assertEqual("Download completed: A (1 MB)", blocks[2])


class RunTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.path = Path(self.directory.name) / "state" / "system-watcher.json"

    def test_first_run_saves_the_baseline_and_asks_for_the_latest_notice(self):
        client = FakeClient([problem()], [notice(12, "Download completed: A (1 MB)")])

        message = watcher.run(client, self.path)

        saved = json.loads(self.path.read_text())

        self.assertEqual("", message)
        self.assertEqual(12, saved["last_event_id"])
        self.assertTrue(saved["baseline_done"])
        self.assertEqual(("system_events", {"kind": "notice", "limit": 1}), client.calls[1])

    def test_later_runs_page_notices_from_the_cursor(self):
        watcher.save_state(self.path, settled(last_event_id=12))
        client = FakeClient([], [notice(13, "Download completed: B (2 MB)")])

        message = watcher.run(client, self.path)

        self.assertEqual("Download completed: B (2 MB)", message)
        self.assertEqual(
            ("system_events", {"after_id": 12, "kind": "notice", "limit": watcher.EVENT_PAGE}),
            client.calls[1],
        )
        self.assertEqual(13, json.loads(self.path.read_text())["last_event_id"])

    def test_an_mcp_error_raises_and_leaves_the_state_untouched(self):
        watcher.save_state(self.path, settled(last_event_id=5))
        before = self.path.read_text()

        with self.assertRaises(watcher.McpError):
            watcher.run(FakeClient(error=watcher.McpError("system_problems failed")), self.path)

        self.assertEqual(before, self.path.read_text())

    def test_state_is_written_atomically_with_no_stray_files(self):
        watcher.save_state(self.path, settled(last_event_id=1))
        watcher.save_state(self.path, settled(last_event_id=2))

        self.assertEqual(2, json.loads(self.path.read_text())["last_event_id"])
        self.assertEqual([self.path.name], [entry.name for entry in self.path.parent.iterdir()])


class FakeConnection:
    def __init__(self, script, log):
        self._script = script
        self._log = log
        self._reply = None

    def request(self, method, path, body=None, headers=None):
        payload = json.loads(body)
        self._log.append((path, payload, headers))
        self._reply = self._script(payload)

    def getresponse(self):
        return self

    @property
    def status(self):
        return self._reply[0]

    def read(self):
        return self._reply[2].encode("utf-8")

    def getheaders(self):
        return list(self._reply[1].items())

    def close(self):
        pass


class McpClientTest(unittest.TestCase):
    def client(self, script):
        self.log = []

        return watcher.McpClient("secret", lambda: FakeConnection(script, self.log))

    def test_handshake_then_tool_call_carries_token_host_and_session(self):
        def script(payload):
            if payload["method"] == "initialize":
                return 200, {"Mcp-Session-Id": "abc"}, json.dumps({"result": {}})

            if payload["method"] == "notifications/initialized":
                return 202, {}, ""

            text = json.dumps({"problems": []})
            body = json.dumps({"result": {"content": [{"type": "text", "text": text}]}})

            return 200, {}, f"event: message\ndata: {body}\n\n"

        client = self.client(script)
        client.start()

        self.assertEqual({"problems": []}, client.call_tool("system_problems", {}))

        path, _, headers = self.log[-1]

        self.assertEqual("/mcp/system", path)
        self.assertEqual("Bearer secret", headers["Authorization"])
        self.assertEqual("home.example.com", headers["Host"])
        self.assertEqual("abc", headers["Mcp-Session-Id"])

    def test_an_http_error_raises(self):
        client = self.client(lambda payload: (403, {}, ""))

        with self.assertRaises(watcher.McpError):
            client.start()

    def test_a_tool_error_raises(self):
        client = self.client(lambda payload: (200, {}, json.dumps({"result": {"isError": True, "content": []}})))

        with self.assertRaises(watcher.McpError):
            client.call_tool("system_events", {})


class ParseBodyTest(unittest.TestCase):
    def test_reads_plain_json_and_event_stream_bodies(self):
        self.assertEqual({"a": 1}, watcher.parse_body('{"a": 1}'))
        self.assertEqual({"a": 1}, watcher.parse_body('event: message\ndata: {"a": 1}\n\n'))

    def test_an_empty_body_raises(self):
        with self.assertRaises(watcher.McpError):
            watcher.parse_body("")


if __name__ == "__main__":
    unittest.main()
