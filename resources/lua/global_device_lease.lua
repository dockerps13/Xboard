-- Global distinct-IP lease authority, Redis Lua 5.1.
--
-- KEYS[1] = account-specific Redis hash key; generated and scoped by panel.
-- ARGV[1] = action: acquire | renew | release | count
-- ARGV[2] = opaque owner token (node instance + unique connection token)
-- ARGV[3] = canonical IP (validated by the API before EVAL)
-- ARGV[4] = account device limit, positive integer
-- ARGV[5] = lease TTL in milliseconds, positive integer
--
-- Hash field = owner token, value = canonicalIP .. "|" .. expiresAtMs.
-- Script uses Redis TIME (not node clock), prunes expired leases, and atomically
-- counts distinct IPs. Returns [allowed(1/0), distinctCount, ttlMsRemaining].
-- The API MUST verify that caller is authorized for the account before EVAL.
-- No direct node-to-Redis connection should be exposed.
--
-- Important: this implementation is O(active owner leases) per operation.
-- Capacity and input size limits are required before production use.

local key = KEYS[1]
local action = ARGV[1]
local owner = ARGV[2]
local ip = ARGV[3]
local limit = tonumber(ARGV[4])
local ttl = tonumber(ARGV[5])
if not action or (action ~= "acquire" and action ~= "renew" and action ~= "release" and action ~= "count") then
    return redis.error_reply("invalid action")
end
if not key or key == "" or not owner or owner == "" then
    return redis.error_reply("missing key or owner")
end
if not limit or limit < 1 or limit ~= math.floor(limit) or not ttl or ttl < 1 or ttl ~= math.floor(ttl) then
    return redis.error_reply("invalid limit or ttl")
end
if action == "acquire" and (not ip or ip == "") then
    return redis.error_reply("missing IP")
end

local t = redis.call("TIME")
local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
local all = redis.call("HGETALL", key)
local distinct = {}
local count = 0
local currentIP = nil
local currentExpiry = nil
local maxExpiry = 0

for i = 1, #all, 2 do
    local token = all[i]
    local value = all[i + 1]
    local sep = string.find(value, "|", 1, true)
    local expiry = sep and tonumber(string.sub(value, sep + 1)) or nil
    if not sep or not expiry or expiry <= now then
        redis.call("HDEL", key, token)
    else
        local leaseIP = string.sub(value, 1, sep - 1)
        if not distinct[leaseIP] then
            distinct[leaseIP] = true
            count = count + 1
        end
        if expiry > maxExpiry then maxExpiry = expiry end
        if token == owner then
            currentIP = leaseIP
            currentExpiry = expiry
        end
    end
end


-- A lowered account limit must be enforced on the very next authority
-- operation. Select survivor IPs deterministically (lexicographic order),
-- keeping *all* owners of each surviving IP. Evicted owners fail their next
-- renewal and their node revokes the corresponding live connections.
-- Do not trust stale client-side limit snapshots.
if count > limit then
    local ordered = {}
    for activeIP in pairs(distinct) do
        ordered[#ordered + 1] = activeIP
    end
    table.sort(ordered)
    local keep = {}
    for i = 1, limit do
        keep[ordered[i]] = true
    end
    distinct = {}
    count = 0
    maxExpiry = 0
    for i = 1, #all, 2 do
        local token = all[i]
        local value = all[i + 1]
        local sep = string.find(value, "|", 1, true)
        local expiry = sep and tonumber(string.sub(value, sep + 1)) or nil
        if expiry and expiry > now then
            local leaseIP = string.sub(value, 1, sep - 1)
            if not keep[leaseIP] then
                redis.call("HDEL", key, token)
                if token == owner then
                    currentIP = nil
                    currentExpiry = nil
                end
            else
                if not distinct[leaseIP] then
                    distinct[leaseIP] = true
                    count = count + 1
                end
                if expiry > maxExpiry then maxExpiry = expiry end
            end
        end
    end
end

if action == "release" then
    if currentIP then
        redis.call("HDEL", key, owner)
        -- Recount after release to correctly handle shared IP slots.
        local remaining = redis.call("HVALS", key)
        distinct = {}
        count = 0
        maxExpiry = 0
        for _, value in ipairs(remaining) do
            local sep = string.find(value, "|", 1, true)
            local leaseIP = string.sub(value, 1, sep - 1)
            local expiry = tonumber(string.sub(value, sep + 1))
            if not distinct[leaseIP] then
                distinct[leaseIP] = true
                count = count + 1
            end
            if expiry > maxExpiry then maxExpiry = expiry end
        end
    end
elseif action == "renew" then
    if not currentIP then return {0, count, 0} end
    currentExpiry = now + ttl
    redis.call("HSET", key, owner, currentIP .. "|" .. currentExpiry)
    if currentExpiry > maxExpiry then maxExpiry = currentExpiry end
elseif action == "acquire" then
    if currentIP and currentIP ~= ip then
        return {0, count, 0}
    end
    if not currentIP and not distinct[ip] and count >= limit then
        return {0, count, 0}
    end
    if not currentIP and not distinct[ip] then count = count + 1 end
    currentExpiry = now + ttl
    redis.call("HSET", key, owner, ip .. "|" .. currentExpiry)
    if currentExpiry > maxExpiry then maxExpiry = currentExpiry end
end

if maxExpiry > now then
    redis.call("PEXPIREAT", key, maxExpiry)
else
    redis.call("DEL", key)
end
local remainingTTL = 0
if currentExpiry and (action == "renew" or action == "acquire") then
    remainingTTL = currentExpiry - now
end
return {1, count, remainingTTL}
