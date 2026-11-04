<?php

namespace App\Models;

class TradeDocumentDetail extends ImmutableInventoryRecord
{
    public $timestamps = false;

    protected $hidden = ['payload_hash'];
}
