<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Initiated            = 'INITIATED';
    case Authorized           = 'AUTHORIZED';
    case PreSettlementReview  = 'PRE_SETTLEMENT_REVIEW';
    case Captured             = 'CAPTURED';
    case Settled              = 'SETTLED';
    case Voided               = 'VOIDED';
    case PartiallyRefunded    = 'PARTIALLY_REFUNDED';
    case Refunded             = 'REFUNDED';
    case Failed               = 'FAILED';
}
