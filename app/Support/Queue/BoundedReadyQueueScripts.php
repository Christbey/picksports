<?php

namespace App\Support\Queue;

final class BoundedReadyQueueScripts
{
    public const MAX_JOBS = 1000;

    public const MAX_PAYLOAD_BYTES = 262144;

    public const MAX_TOTAL_BYTES = 4194304;

    public static function snapshot(): string
    {
        return self::bounds()."\nreturn boundedQueue(KEYS[1], true)";
    }

    public static function remove(): string
    {
        return self::bounds().<<<'LUA'

if #ARGV > MAX_JOBS then
    return {'candidate_count', #ARGV, 0}
end
local candidates = {}
local candidateBytes = 0
for _, raw in ipairs(ARGV) do
    candidateBytes = candidateBytes + #raw
    if #raw > MAX_PAYLOAD_BYTES or candidateBytes > MAX_TOTAL_BYTES then
        return {'candidate_bytes', #ARGV, candidateBytes}
    end
    candidates[raw] = (candidates[raw] or 0) + 1
end

-- Complete all capacity/type checks before the first mutation.
local current = boundedQueue(KEYS[1], false)
if current[1] ~= 'ok' then return current end
local notifyType = redis.call('type', KEYS[2]).ok
if notifyType ~= 'none' and notifyType ~= 'list' then
    return {'notify_type', current[2], current[3]}
end
local notifyCount = redis.call('llen', KEYS[2])
local removed = 0
for raw, count in pairs(candidates) do
    removed = removed + redis.call('lrem', KEYS[1], count, raw)
end
local trimCount = math.min(removed, notifyCount)
if trimCount > 0 then
    redis.call('ltrim', KEYS[2], trimCount, -1)
end
return {'ok', removed}
LUA;
    }

    private static function bounds(): string
    {
        return 'local MAX_JOBS = '.self::MAX_JOBS."\n"
            .'local MAX_PAYLOAD_BYTES = '.self::MAX_PAYLOAD_BYTES."\n"
            .'local MAX_TOTAL_BYTES = '.self::MAX_TOTAL_BYTES."\n".<<<'LUA'
local function boundedQueue(key, includePayloads)
    local queueType = redis.call('type', key).ok
    if queueType ~= 'none' and queueType ~= 'list' then
        return {'queue_type', 0, 0}
    end
    local count = redis.call('llen', key)
    if count > MAX_JOBS then return {'queue_count', count, 0} end
    local bytes = 0
    local values = {}
    for index = 0, count - 1 do
        local raw = redis.call('lindex', key, index)
        bytes = bytes + #raw
        if #raw > MAX_PAYLOAD_BYTES then return {'payload_bytes', count, bytes} end
        if bytes > MAX_TOTAL_BYTES then return {'queue_bytes', count, bytes} end
        if includePayloads then table.insert(values, raw) end
    end
    return {'ok', count, bytes, values}
end
LUA;
    }
}
