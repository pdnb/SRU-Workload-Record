<?php

/*
|--------------------------------------------------------------------------
| Oracle (VNMIS) workload source
|--------------------------------------------------------------------------
|
| Positions, per-position workload structures and staff positions are read
| from the VNMIS schema (see database/VNMIS.sql) by App\Services\Oracle\
| OracleWorkloadReader. Staff names/emails come from VNMIS views that read
| the PROMIS schema, so the account needs SELECT on those views.
|
*/

return [

    'enabled' => (bool) env('ORACLE_SYNC_ENABLED', false),

    'connection' => env('ORACLE_SYNC_CONNECTION', 'oracle'),

    // Owner of the HR_EV* tables and VW_HR_* views.
    'schema' => env('ORACLE_SCHEMA', 'VNMIS'),

];
