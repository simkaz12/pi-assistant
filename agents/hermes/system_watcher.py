import http.client
import json
import os
import socket
import ssl
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

HOST = "home.example.com"
PINNED_ADDRESS = "127.0.0.1"
PORT = 443
ENDPOINT = "/mcp/system"
TIMEOUT = 20
EVENT_PAGE = 200

HERMES_HOME = Path.home() / ".hermes"
TOKEN_PATH = HERMES_HOME / "secrets" / "pi-system-mcp-token"
STATE_PATH = HERMES_HOME / "cron" / "state" / "system-watcher.json"


class McpError(RuntimeError):
    pass


class LocalMcpConnection(http.client.HTTPSConnection):
    def __init__(self, timeout=TIMEOUT):
        super().__init__(HOST, PORT, timeout=timeout, context=ssl.create_default_context())

    def connect(self):
        sock = socket.create_connection((PINNED_ADDRESS, PORT), self.timeout)
        self.sock = self._context.wrap_socket(sock, server_hostname=HOST)


class McpClient:
    def __init__(self, token, connection_factory=LocalMcpConnection):
        self._token = token
        self._connection_factory = connection_factory
        self._session = None
        self._next_id = 0

    def start(self):
        response = self._post(
            "initialize",
            {
                "protocolVersion": "2025-06-18",
                "capabilities": {},
                "clientInfo": {"name": "system-watcher", "version": "1"},
            },
        )
        self._session = response["headers"].get("Mcp-Session-Id")
        self._post("notifications/initialized", {}, notification=True)

    def call_tool(self, name, arguments):
        response = self._post("tools/call", {"name": name, "arguments": arguments})
        result = response["body"].get("result") or {}
        content = result.get("content") or []

        if result.get("isError") or not content:
            raise McpError(f"tool {name} failed")

        return json.loads(content[0]["text"])

    def _post(self, method, params, notification=False):
        payload = {"jsonrpc": "2.0", "method": method, "params": params}

        if not notification:
            self._next_id += 1
            payload["id"] = self._next_id

        headers = {
            "Authorization": f"Bearer {self._token}",
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "Host": HOST,
        }

        if self._session:
            headers["Mcp-Session-Id"] = self._session

        connection = self._connection_factory()

        try:
            connection.request("POST", ENDPOINT, body=json.dumps(payload), headers=headers)
            response = connection.getresponse()
            raw = response.read().decode("utf-8")
            status = response.status
            response_headers = {key: value for key, value in response.getheaders()}
        finally:
            connection.close()

        if status >= 400:
            raise McpError(f"{method} returned HTTP {status}")

        if notification:
            return {"headers": response_headers, "body": {}}

        body = parse_body(raw)

        if "error" in body:
            raise McpError(f"{method} failed: {body['error']}")

        return {"headers": response_headers, "body": body}


def parse_body(raw):
    text = raw.strip()

    if text.startswith("{"):
        return json.loads(text)

    for line in text.splitlines():
        if line.startswith("data:"):
            return json.loads(line[5:].strip())

    raise McpError("empty MCP response")


def default_state():
    return {"alerted": {}, "last_event_id": 0, "baseline_done": False}


def local_clock(iso):
    return datetime.fromisoformat(iso).astimezone().strftime("%H:%M")


def minutes_between(iso, now):
    started = datetime.fromisoformat(iso)

    return max(0, round((now - started).total_seconds() / 60))


def decide(state, problems, events, now=None):
    now = now or datetime.now(timezone.utc)
    alertable = {p["key"]: p for p in problems if p.get("alertable")}
    newest = max([event["id"] for event in events], default=state["last_event_id"])

    new_state = {
        "alerted": {
            key: {"title": problem["title"], "since": problem["since"]}
            for key, problem in alertable.items()
        },
        "last_event_id": max(newest, state["last_event_id"]),
        "baseline_done": True,
    }

    if not state["baseline_done"]:
        return "", new_state

    lines = [
        f"CRITICAL {problem['title']} (since {local_clock(problem['since'])})"
        for key, problem in alertable.items()
        if key not in state["alerted"]
    ]

    lines += [
        f"RESOLVED {known['title']} (lasted {minutes_between(known['since'], now)} min)"
        for key, known in state["alerted"].items()
        if key not in alertable
    ]

    lines += [event.get("body") or event["title"] for event in events]

    return "\n\n".join(lines), new_state


def load_state(path):
    if not path.exists():
        return default_state()

    return {**default_state(), **json.loads(path.read_text())}


def save_state(path, state):
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(dir=path.parent, prefix=".system-watcher-")

    try:
        with os.fdopen(descriptor, "w") as handle:
            json.dump(state, handle)
            handle.flush()
            os.fsync(handle.fileno())

        os.replace(temporary, path)
    except BaseException:
        if os.path.exists(temporary):
            os.unlink(temporary)

        raise


def fetch(client, state):
    problems = client.call_tool("system_problems", {})["problems"]

    if state["baseline_done"]:
        arguments = {"after_id": state["last_event_id"], "kind": "notice", "limit": EVENT_PAGE}
    else:
        arguments = {"kind": "notice", "limit": 1}

    events = client.call_tool("system_events", arguments)["events"]

    return problems, events


def run(client, state_path=STATE_PATH):
    state = load_state(state_path)
    problems, events = fetch(client, state)
    message, new_state = decide(state, problems, events)

    save_state(state_path, new_state)

    return message


def main():
    token = TOKEN_PATH.read_text().strip()
    client = McpClient(token)
    client.start()

    message = run(client)

    if message:
        sys.stdout.write(message)


if __name__ == "__main__":
    main()
