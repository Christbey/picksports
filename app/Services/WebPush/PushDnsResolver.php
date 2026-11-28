<?php

namespace App\Services\WebPush;

use App\Services\Http\DnsResolver;

/** Backward-compatible injection point for push transport. */
class PushDnsResolver extends DnsResolver {}
