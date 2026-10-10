#!/usr/bin/env python3
"""Tool calling in the AI gateway, against a loopback mock (no paid provider calls)."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import subprocess
import tempfile
from threading import Thread
from support import copy_core, write_json

requests = []   # (path, headers, body)
queue = []      # scripted (status, payload) answers, consumed in order


class Provider(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        requests.append((self.path, dict(self.headers), body))
        status, payload = queue.pop(0) if queue else (500, {"error": {"message": "unscripted request"}})
        data = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)


def openai_reply(content=None, calls=None, finish="stop", usage=(100, 20)):
    message = {"role": "assistant", "content": content}
    if calls:
        message["tool_calls"] = [{"id": i, "type": "function", "function": {"name": n, "arguments": a}} for i, n, a in calls]
    return 200, {"choices": [{"message": message, "finish_reason": finish}], "usage": {"prompt_tokens": usage[0], "completion_tokens": usage[1]}}


def anthropic_reply(blocks, stop="end_turn", usage=(100, 20)):
    return 200, {"content": blocks, "stop_reason": stop, "model": "claude-sonnet-4-6", "usage": {"input_tokens": usage[0], "output_tokens": usage[1]}}


TOOLS = [
    {"name": "get_page_capabilities", "description": "Describe a page.",
     "parameters": {"type": "object", "additionalProperties": False, "required": ["target"], "properties": {
         "target": {"type": "string", "pattern": "^page:[a-z_]+$", "maxLength": 40},
         "depth": {"type": "integer", "minimum": 1, "maximum": 3},
         "mode": {"type": "string", "enum": ["short", "full"]}}}},
    {"name": "list_content", "description": "List content.", "parameters": {"type": "object", "properties": {}}},
]
SECRET = "SECRET-PROMPT-7f3a"

with tempfile.TemporaryDirectory(prefix="nibbly-gateway-smoke-") as folder:
    root = copy_core(folder)
    server = ThreadingHTTPServer(("127.0.0.1", 0), Provider)
    Thread(target=server.serve_forever, daemon=True).start()
    prefix = "require 'includes/ai/ai-helper.php'; $in = json_decode(stream_get_contents(STDIN), true); "

    def php(code, payload=None, check=True):
        result = subprocess.run(["php", "-d", "display_errors=0", "-r", prefix + code], cwd=root, text=True,
                                input=json.dumps(payload), capture_output=True)
        if check and result.returncode:
            raise AssertionError(result.stdout + result.stderr)
        return result.stdout

    def php_json(code, payload=None):
        return json.loads(php(code, payload))

    def configure(provider, model):
        settings = php_json("echo json_encode(nibblyAiDefaults());")
        settings.update(enabled=True, provider=provider, allowLocalProvider=True, chatModel=model,
                        baseUrl=f"http://127.0.0.1:{server.server_port}", apiKey="")
        settings["providerCredentials"][provider]["baseUrl"] = settings["baseUrl"]
        write_json(root / "content/ai-settings.json", settings)

    def turn(messages, tools=TOOLS, options=None):
        return php_json("echo json_encode(nibblyAiChatWithTools($in['m'], $in['t'], $in['o']));", {"m": messages, "t": tools, "o": options or {}})

    def usage():
        path = root / "content/ai-usage.json"
        return json.loads(path.read_text()) if path.exists() else {"reservations": {}, "days": {}}

    try:
        conversation = [{"role": "system", "content": "You edit websites."}, {"role": "user", "content": SECRET + " Show me the home page."}]

        # ---- OpenAI-compatible: tool round trip -------------------------------------------
        configure("openai-compatible", "gpt-4.1-mini")
        queue[:] = [openai_reply(calls=[("call_A", "get_page_capabilities", '{"target":"page:de_home","depth":2}'),
                                        ("call_B", "list_content", "")], finish="tool_calls", usage=(120, 30))]
        first = turn(conversation)
        assert first["kind"] == "tool_calls" and first["stopReason"] == "tool_calls", first
        assert [c["name"] for c in first["toolCalls"]] == ["get_page_capabilities", "list_content"]
        assert first["toolCalls"][0]["arguments"] == {"target": "page:de_home", "depth": 2} and first["toolCalls"][0]["error"] is None
        assert first["toolCalls"][1]["arguments"] == [] and first["toolCalls"][1]["error"] is None, "empty arguments are an empty object"
        assert first["usage"]["inputTokens"] == 120 and first["usage"]["outputTokens"] == 30 and first["requestId"].startswith("req_")
        path, headers, body = requests[-1]
        assert path == "/chat/completions" and body["tool_choice"] == "auto" and body["stream"] is False and body["max_tokens"] > 0, body
        assert body["tools"][0] == {"type": "function", "function": {"name": "get_page_capabilities", "description": "Describe a page.", "parameters": TOOLS[0]["parameters"]}}, body["tools"][0]
        assert body["tools"][1]["function"]["parameters"] == {"type": "object", "properties": {}}, "empty properties must encode as an object"
        assert body["messages"] == conversation, body["messages"]
        assistant = first["message"]
        assert assistant["role"] == "assistant" and [c["id"] for c in assistant["toolCalls"]] == ["call_A", "call_B"]

        queue[:] = [openai_reply(content="Die Startseite hat zwei Bereiche.", usage=(300, 12))]
        second = turn(conversation + [assistant,
                                      {"role": "tool", "toolCallId": "call_A", "content": '{"fields":2}'},
                                      {"role": "tool", "toolCallId": "call_B", "content": "[]"}])
        assert second["kind"] == "final" and second["text"] == "Die Startseite hat zwei Bereiche." and second["toolCalls"] == [] and "toolCalls" not in second["message"]
        sent = requests[-1][2]["messages"]
        assert sent[2] == {"role": "assistant", "content": None, "tool_calls": [
            {"id": "call_A", "type": "function", "function": {"name": "get_page_capabilities", "arguments": '{"target":"page:de_home","depth":2}'}},
            {"id": "call_B", "type": "function", "function": {"name": "list_content", "arguments": "{}"}}]}, sent[2]
        assert sent[3] == {"role": "tool", "tool_call_id": "call_A", "content": '{"fields":2}'} and sent[4]["tool_call_id"] == "call_B", sent[3:]

        # ---- Anthropic: tool_use / tool_result pairs --------------------------------------
        configure("anthropic", "claude-sonnet-4-6")
        queue[:] = [anthropic_reply([{"type": "text", "text": "Ich schaue nach."},
                                     {"type": "tool_use", "id": "toolu_1", "name": "get_page_capabilities", "input": {"target": "page:de_home"}},
                                     {"type": "tool_use", "id": "toolu_2", "name": "list_content", "input": {}}], stop="tool_use", usage=(200, 40))]
        first = turn(conversation)
        assert first["kind"] == "tool_calls" and first["text"] == "Ich schaue nach." and first["stopReason"] == "tool_calls", first
        assert [c["id"] for c in first["toolCalls"]] == ["toolu_1", "toolu_2"] and first["usage"]["inputTokens"] == 200
        path, headers, body = requests[-1]
        assert path == "/messages" and headers.get("anthropic-version") == "2023-06-01", (path, headers)
        assert body["system"] == "You edit websites." and body["tool_choice"] == {"type": "auto"} and "stream" not in body, body
        assert body["messages"] == [{"role": "user", "content": conversation[1]["content"]}], body["messages"]
        assert body["tools"][0] == {"name": "get_page_capabilities", "description": "Describe a page.", "input_schema": TOOLS[0]["parameters"]}
        assert body["tools"][1]["input_schema"] == {"type": "object", "properties": {}}

        queue[:] = [anthropic_reply([{"type": "text", "text": "Fertig."}], usage=(400, 5))]
        second = turn(conversation + [first["message"],
                                      {"role": "tool", "toolCallId": "toolu_1", "content": '{"fields":2}'},
                                      {"role": "tool", "toolCallId": "toolu_2", "content": "Nicht erlaubt", "isError": True},
                                      {"role": "user", "content": "Danke, weiter."}])
        assert second["kind"] == "final" and second["text"] == "Fertig."
        sent = requests[-1][2]["messages"]
        assert [m["role"] for m in sent] == ["user", "assistant", "user"], [m["role"] for m in sent]
        assert sent[1]["content"] == [{"type": "text", "text": "Ich schaue nach."},
                                      {"type": "tool_use", "id": "toolu_1", "name": "get_page_capabilities", "input": {"target": "page:de_home"}},
                                      {"type": "tool_use", "id": "toolu_2", "name": "list_content", "input": {}}], sent[1]["content"]
        assert sent[2]["content"] == [{"type": "tool_result", "tool_use_id": "toolu_1", "content": '{"fields":2}'},
                                      {"type": "tool_result", "tool_use_id": "toolu_2", "content": "Nicht erlaubt", "is_error": True},
                                      {"type": "text", "text": "Danke, weiter."}], "results share one user message, errors are flagged"

        # ---- Invalid tool calls become per-call errors ------------------------------------
        configure("openai-compatible", "gpt-4.1-mini")
        queue[:] = [openai_reply(calls=[
            ("call_1", "get_page_capabilities", '{"target": '),
            ("call_2", "delete_everything", "{}"),
            ("call_3", "get_page_capabilities", "{}"),
            ("call_4", "get_page_capabilities", '{"target":"page:de_home","extra":1}'),
            ("call_5", "get_page_capabilities", '{"target":"../etc/passwd"}'),
            ("call_6", "get_page_capabilities", '{"target":"page:de_home","depth":"2"}'),
            ("call_7", "get_page_capabilities", '{"target":"page:de_home","depth":9,"mode":"huge"}'),
            ("call_8", "get_page_capabilities", '[1,2]'),
            ("", "list_content", "{}"),
            ("call_1", "list_content", "{}"),
        ], finish="tool_calls")]
        result = turn(conversation)
        errors = [c["error"] for c in result["toolCalls"]]
        assert "not valid JSON" in errors[0] and "Unknown tool 'delete_everything'" in errors[1] and "get_page_capabilities" in errors[1], errors[:2]
        assert "arguments.target is required" in errors[2] and "arguments.extra is not an allowed argument" in errors[3], errors[2:4]
        assert "pattern" in errors[4] and "type integer" in errors[5] and "at most 3" in errors[6] and "one of: " in errors[6], errors[4:7]
        assert "must be a JSON object" in errors[7] and errors[8] is None and errors[9] is None, errors[7:]
        ids = [c["id"] for c in result["toolCalls"]]
        assert len(set(ids)) == 10 and all(len(i) >= 6 for i in ids), ids
        assert all(c["arguments"] == [] for c in result["toolCalls"][:8]), "rejected calls carry no arguments"
        # A local server returning arguments as an object instead of a string still works
        queue[:] = [(200, {"choices": [{"message": {"content": "", "tool_calls": [
            {"id": "call_x", "function": {"name": "get_page_capabilities", "arguments": {"target": "page:de_home"}}}]}, "finish_reason": "tool_calls"}]})]
        result = turn(conversation)
        assert result["toolCalls"][0]["arguments"] == {"target": "page:de_home"} and result["toolCalls"][0]["error"] is None
        assert result["usage"]["inputTokens"] > 0, "usage falls back to estimates when the provider reports none"

        # ---- Message history integrity ----------------------------------------------------
        def clean(messages, maximum=60):
            return php_json("echo json_encode(nibblyAgentCleanMessages($in['m'], $in['n']));", {"m": messages, "n": maximum})

        def rejects(messages):
            out = php("try { nibblyAgentCleanMessages($in); echo 'accepted'; } catch (InvalidArgumentException $e) { echo 'rejected'; }", messages)
            return out == "rejected"

        call = lambda i, name="list_content": {"id": i, "name": name, "arguments": {}}
        pair = lambda i: [{"role": "assistant", "content": "", "toolCalls": [call(i)]}, {"role": "tool", "toolCallId": i, "content": "ok " + i}]
        user = {"role": "user", "content": "go"}
        assert rejects([user, {"role": "tool", "toolCallId": "c1", "content": "x"}]), "orphan tool result"
        assert rejects([user, pair("c1")[0]]), "tool call without result"
        assert rejects([user] + pair("c1") + pair("c1")), "duplicate call id"
        assert rejects([user, {"role": "assistant", "content": "", "toolCalls": [call("c1")]}, {"role": "user", "content": "hm"}]), "result must follow the call directly"
        assert rejects([user, {"role": "assistant", "content": "", "toolCalls": [call("c1")]}, {"role": "tool", "toolCallId": "other", "content": "x"}]), "result for a different call"
        assert rejects([user, {"role": "robot", "content": "x"}]), "unknown role"
        assert rejects([user, {"role": "assistant", "content": "", "toolCalls": [call("bad id!")]}]), "unusable call id"
        # Valid parallel calls, answered in any order
        parallel = [user, {"role": "assistant", "content": "", "toolCalls": [call("c1"), call("c2")]},
                    {"role": "tool", "toolCallId": "c2", "content": "b"}, {"role": "tool", "toolCallId": "c1", "content": "a"}]
        assert [m["role"] for m in clean(parallel)] == ["user", "assistant", "tool", "tool"]
        # Trimming drops whole exchanges and never splits a pair
        history = [{"role": "system", "content": "rules"}, user]
        for index in range(8):
            history += pair(f"c{index}")
        trimmed = clean(history, 8)
        assert [m["role"] for m in trimmed][:2] == ["system", "user"] and len(trimmed) == 8, trimmed
        kept = [m["toolCallId"] for m in trimmed if m["role"] == "tool"]
        called = [c["id"] for m in trimmed for c in m.get("toolCalls", [])]
        assert kept == called == ["c5", "c6", "c7"], (kept, called)
        # Caps: 12,000 characters, UTF-8 safe, tool roles survive
        huge = clean([user, {"role": "assistant", "content": "", "toolCalls": [call("c1")]}, {"role": "tool", "toolCallId": "c1", "content": "ä" * 30000}])
        assert huge[2]["role"] == "tool" and huge[2]["content"].startswith("ä" * 12000) and huge[2]["content"].endswith("[truncated]") and len(huge[2]["content"]) < 12100
        assert clean([user, {"role": "assistant", "content": "   "}, {"role": "user", "content": ""}]) == [user], "empty messages are dropped"
        assert php("try { nibblyAiChatWithTools([['role'=>'user','content'=>'x'], ['role'=>'tool','toolCallId'=>'c9','content'=>'orphan']], $in); } catch (Throwable $e) { echo get_class($e); }", TOOLS) == "InvalidArgumentException"
        # tool names
        assert php("try { nibblyAiChatWithTools([['role'=>'user','content'=>'x']], [['name'=>'bad name!']]); } catch (Throwable $e) { echo get_class($e); }") == "InvalidArgumentException"
        assert php("try { nibblyAiChatWithTools([['role'=>'user','content'=>'x']], []); } catch (Throwable $e) { echo get_class($e); }") == "InvalidArgumentException"

        # ---- Kie has no tool calling ------------------------------------------------------
        configure("kie", "gpt-5-6-luna")
        before = len(requests)
        message = php("try { nibblyAiChatWithTools([['role'=>'user','content'=>'x']], $in); } catch (Throwable $e) { echo $e->getMessage(); }", TOOLS)
        assert "does not support tool calling" in message and len(requests) == before, "Kie must be refused before any request"
        supports = php_json("echo json_encode(array_map(fn($p) => nibblyAiSupportsToolCalling(['provider'=>$p, 'baseUrl'=>'http://localhost:1/v1']), ['openai-compatible','openrouter','anthropic','kie']));")
        assert supports == [True, True, True, False], supports
        assert php_json("echo json_encode([nibblyAiSupportsToolCalling(['provider'=>'openai-compatible','baseUrl'=>'https://openrouter.ai/api/v1']), nibblyAiProviderKey(['provider'=>'openai-compatible','baseUrl'=>'https://api.kie.ai']), nibblyAiProviderKey(['provider'=>'openai-compatible','baseUrl'=>'http://127.0.0.1:11434/v1'])]);") == [True, "kie", "openai-compatible"]

        # ---- The old chat bodies are unchanged --------------------------------------------
        configure("openai-compatible", "gpt-4.1-mini")
        queue[:] = [(200, {"choices": [{"message": {"content": "Hallo"}, "finish_reason": "stop"}], "usage": {"prompt_tokens": 5, "completion_tokens": 1}})]
        old = php_json("echo json_encode(nibblyAiChat([['role'=>'user','content'=>'Hi']], ['maxOutputTokens'=>64]));")
        assert old["text"] == "Hallo"
        assert list(requests[-1][2]) == ["model", "messages", "max_tokens", "temperature", "stream"] and requests[-1][2]["messages"] == [{"role": "user", "content": "Hi"}], requests[-1][2]
        configure("anthropic", "claude-sonnet-4-6")
        queue[:] = [anthropic_reply([{"type": "text", "text": "Hallo"}], usage=(5, 1))]
        old = php_json("echo json_encode(nibblyAiChat([['role'=>'system','content'=>'Sys'], ['role'=>'user','content'=>'Hi']], ['maxOutputTokens'=>64]));")
        assert old["text"] == "Hallo" and requests[-1][0] == "/messages"
        assert list(requests[-1][2]) == ["model", "max_tokens", "messages", "system", "temperature"], list(requests[-1][2])
        assert "tools" not in requests[-1][2] and "tool_choice" not in requests[-1][2]
        assert requests[-1][2]["messages"] == [{"role": "user", "content": "Hi"}] and requests[-1][2]["system"] == "Sys"

        # ---- Ledger: every turn is its own settled top-level request ----------------------
        configure("openai-compatible", "gpt-4.1-mini")
        before = usage()
        known = set(before.get("reservations", {}))
        queue[:] = [openai_reply(content="eins", usage=(1000, 100)), openai_reply(content="zwei", usage=(2000, 200))]
        one = turn(conversation, options={"_requestId": "agent_run1_1"})
        two = turn(conversation, options={"_requestId": "agent_run1_2"})
        after = usage()
        added = {k: v for k, v in after["reservations"].items() if k not in known}
        assert sorted(added) == ["agent_run1_1", "agent_run1_2"] and one["requestId"] == "agent_run1_1" and two["requestId"] == "agent_run1_2", sorted(added)
        assert all(v["status"] == "settled" for v in added.values()), added
        today = next(iter(after["days"]))
        previous = before["days"].get(today, {"requests": 0, "inputTokens": 0, "outputTokens": 0})
        assert after["days"][today]["requests"] - previous["requests"] == 2, "each turn counts as one request"
        assert after["days"][today]["inputTokens"] - previous["inputTokens"] == 3000 and after["days"][today]["outputTokens"] - previous["outputTokens"] == 300, "usage recorded for every call"
        before_requests = len(requests)
        nested = php("try { nibblyAiWithRequest(fn() => nibblyAiChatWithTools([['role'=>'user','content'=>'x']], $in)); } catch (Throwable $e) { echo get_class($e); }", TOOLS)
        assert nested == "LogicException" and len(requests) == before_requests, "a nested call would lose its usage and is refused before any request"

        # ---- Limits and failures ----------------------------------------------------------
        queue[:] = [(500, {"error": {"message": "upstream exploded"}})]
        message = php("try { nibblyAiChatWithTools($in['m'], $in['t']); } catch (Throwable $e) { echo $e->getMessage(); }", {"m": conversation, "t": TOOLS})
        assert message == "upstream exploded", message
        queue[:] = [openai_reply(content="", finish="length")]
        message = php("try { nibblyAiChatWithTools($in['m'], $in['t']); } catch (Throwable $e) { echo $e->getMessage(); }", {"m": conversation, "t": TOOLS})
        assert "output limit" in message, message
        queue[:] = [openai_reply(content="")]
        message = php("try { nibblyAiChatWithTools($in['m'], $in['t']); } catch (Throwable $e) { echo $e->getMessage(); }", {"m": conversation, "t": TOOLS})
        assert "empty response" in message, message
        before_requests = len(requests)
        message = php("try { nibblyAiChatWithTools(array_map(fn($i) => ['role'=>$i % 2 ? 'assistant' : 'user','content'=>str_repeat('wort ', 2400)], range(0, 39)), $in); } catch (Throwable $e) { echo $e->getMessage(); }", TOOLS)
        assert "too long" in message and len(requests) == before_requests, "oversized input is refused before the request"
        settings = json.loads((root / "content/ai-settings.json").read_text())
        settings["features"]["backendAssistant"] = False
        write_json(root / "content/ai-settings.json", settings)
        message = php("try { nibblyAiChatWithTools([['role'=>'user','content'=>'x']], $in); } catch (Throwable $e) { echo $e->getMessage(); }", TOOLS)
        assert "feature is disabled" in message and len(requests) == before_requests

        # ---- Audit: model, tokens and tool names only -------------------------------------
        audit = "\n".join(path.read_text() for path in (root / "content/ai-audit").glob("*.jsonl"))
        turns = [json.loads(line) for line in audit.splitlines() if '"agent-turn"' in line]
        assert turns, "agent turns are audited"
        with_tools = [t for t in turns if t.get("meta", t).get("tools")]
        entry = with_tools[0].get("meta", with_tools[0])
        assert entry["model"] == "gpt-4.1-mini" and entry["inputTokens"] == 120 and entry["tools"] == ["get_page_capabilities", "list_content"], entry
        for secret in (SECRET, "page:de_home", "Die Startseite hat zwei Bereiche"):
            assert secret not in audit, f"audit log leaks {secret}"
    finally:
        server.shutdown()
        server.server_close()
print("PASS gateway tools: OpenAI and Anthropic round trips, argument errors, paired history, Kie refused, legacy bodies unchanged, ledger per turn, audit without content")
