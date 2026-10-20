local threads = {}
local requests = {}
local index = 0
local kind
expected = 200
bad_status = 0
bad_body = 0
bad_response = 0
workload = ""
function setup(thread) table.insert(threads, thread) end
function init(args)
    kind = args[1]
    assert(kind == "hello" or kind == "db" or kind == "validate", "Choose hello, db or validate")
    workload = kind
    if kind == "validate" then expected = 201 end
    for i = 1, 10000 do
        local headers = { ["Accept"] = "application/json", ["X-Benchmark-Request"] = tostring(i) }
        if kind == "validate" then
            headers["Content-Type"] = "application/json"
            local body = string.format('{"name":"Ada %05d","email":"ada%05d@example.test","age":%d}', i, i, 18 + i % 103)
            requests[i] = wrk.format("POST", "/users", headers, body)
        elseif kind == "db" then
            requests[i] = wrk.format("GET", "/users/" .. i, headers)
        else
            requests[i] = wrk.format("GET", "/hello?request=" .. i, headers)
        end
    end
end
function request() index = index % 10000 + 1; return requests[index] end
function response(status, headers, body)
    local wrong_status = status ~= expected
    local wrong_body = false
    if kind == "hello" then
        wrong_body = body ~= '{"message":"Hello, world"}'
    elseif kind == "db" then
        wrong_body = not body:find('"id":', 1, true) or not body:find('"email":', 1, true)
    else
        wrong_body = not body:find('"name":', 1, true) or not body:find('"email":', 1, true) or not body:find('"age":', 1, true)
    end
    if wrong_status then bad_status = bad_status + 1 end
    if wrong_body then bad_body = bad_body + 1 end
    if wrong_status or wrong_body then bad_response = bad_response + 1 end
end
function done(summary, latency, requests)
    local bad, bodies, responses = 0, 0, 0
    for _, thread in ipairs(threads) do
        bad = bad + thread:get("bad_status")
        bodies = bodies + thread:get("bad_body")
        responses = responses + thread:get("bad_response")
    end
    local selected = threads[1]:get("workload")
    io.write(string.format('BENCH_JSON {"workload":"%s","bad_body":%d,"bad_response":%d,"requests":%d,"duration_us":%d,"rps":%.6f,"p50_us":%.6f,"p99_us":%.6f,"bad_status":%d,"errors":{"connect":%d,"read":%d,"write":%d,"timeout":%d,"status":%d}}\n', selected, bodies, responses, summary.requests, summary.duration, summary.requests / summary.duration * 1000000, latency:percentile(50), latency:percentile(99), bad, summary.errors.connect, summary.errors.read, summary.errors.write, summary.errors.timeout, summary.errors.status))
end
