<?php

use Illuminate\Support\Facades\Schedule;

// Remove expired admin API tokens every day
Schedule::command('sanctum:prune-expired --hours=24')->daily();
