-- Cycles through ids 1..10000 under the prefix given after --, e.g. -- /mysql/users/
local prefix = "/users/"
local i = 0
function init(args) if args[1] then prefix = args[1] end; i = math.random(0, 9999) end
function request() i = i % 10000 + 1; return wrk.format("GET", prefix .. i) end
