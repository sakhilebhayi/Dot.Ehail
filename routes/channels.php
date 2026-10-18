<?php

// Required to exist for bootstrap/app.php's withRouting(channels: ...) to
// register the /broadcasting/auth route -- actual channel authorization
// callbacks live in App\Providers\BroadcastServiceProvider, per the Dot
// Real-Time Standard (see docs/DOT_REALTIME_STANDARD.md in the Dot.Mines
// repo, the ecosystem reference implementation).
