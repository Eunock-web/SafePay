<?php
namespace Safepay\Enums;

enum TransactionStatus: string
{
    case EscrowLock = 'escrow_lock';
    case Releasing = 'releasing';
    case Released = 'released';
    case Failed = 'failed';
}
