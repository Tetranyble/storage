<?php

namespace Tetranyble\Storage\Enums;

enum MediaPurpose: string
{
    case BANNER = 'BANNER';
    case GENERAL = 'GENERAL';
    case IMAGE = 'IMAGE';
    case VIDEO = 'VIDEO';
    case PROFILE = 'PROFILE';
    case LOGO = 'LOGO';
    case FAVICON = 'FAVICON';
    case IDENTITY_DOCUMENT_FRONT = 'IDENTITY_DOCUMENT_FRONT';
    case IDENTITY_DOCUMENT_BACK = 'IDENTITY_DOCUMENT_BACK';
    case GUARANTOR_IDENTITY_DOCUMENT_FRONT = 'GUARANTOR_IDENTITY_DOCUMENT_FRONT';
    case GUARANTOR_IDENTITY_DOCUMENT_BACK = 'GUARANTOR_IDENTITY_DOCUMENT_BACK';
    case BOARD_RESOLUTION = 'BOARD_RESOLUTION';
    case BUSINESS_LICENSE = 'BUSINESS_LICENSE';
    case MEMORANDUM_ARTICLES = 'MEMORANDUM_ARTICLES';
    case NEXT_OF_KIN_ID = 'NEXT_OF_KIN_ID';
    case PAYSLIP = 'PAYSLIP';
    case EMPLOYEE_OFFER_LETTER = 'EMPLOYEE_OFFER_LETTER';
    case EMPLOYEE_CONTRACT = 'EMPLOYEE_CONTRACT';
    case EMPLOYEE_ID_CARD = 'EMPLOYEE_ID_CARD';
    case EMPLOYEE_RESUME = 'EMPLOYEE_RESUME';
    case EMPLOYEE_CERTIFICATE = 'EMPLOYEE_CERTIFICATE';
    case EMPLOYEE_OTHER = 'EMPLOYEE_OTHER';
    case SIGNATURE = 'SIGNATURE';
    case IMPORT_SOURCE = 'IMPORT_SOURCE';
    case BANK_STATEMENT = 'BANK_STATEMENT';
    case LOAN_SUPPORTING_DOCUMENT = 'LOAN_SUPPORTING_DOCUMENT';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }

    public function allowedMimeTypes(): array
    {
        return match ($this) {
            self::VIDEO => [
                'video/mp4',
                'video/x-msvideo',
                'video/quicktime',
                'video/x-ms-wmv',
                'video/mpeg',
                'video/avi',
                'video/webm',
                'video/3gpp',
            ],
            self::FAVICON => [
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/svg+xml',
            ],
            self::IDENTITY_DOCUMENT_FRONT,
            self::IDENTITY_DOCUMENT_BACK,
            self::GUARANTOR_IDENTITY_DOCUMENT_FRONT,
            self::GUARANTOR_IDENTITY_DOCUMENT_BACK,
            self::BOARD_RESOLUTION,
            self::BUSINESS_LICENSE,
            self::MEMORANDUM_ARTICLES,
            self::NEXT_OF_KIN_ID,
            self::PAYSLIP,
            self::BANK_STATEMENT,
            self::LOAN_SUPPORTING_DOCUMENT => [
                'image/jpeg',
                'image/png',
                'application/pdf',
            ],
            default => [
                'image/jpeg',
                'image/png',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
        };
    }
}
