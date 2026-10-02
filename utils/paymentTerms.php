<?php
// utils/paymentTerms.php

declare(strict_types=1);

function paymentTermDefinitions(): array
{
    return [
        'due_on_receipt' => ['label' => 'Due on Receipt', 'days' => 0],
        'net_7' => ['label' => 'Net 7 Days', 'days' => 7],
        'net_15' => ['label' => 'Net 15 Days', 'days' => 15],
        'net_30' => ['label' => 'Net 30 Days', 'days' => 30],
    ];
}

function validPaymentTerms(): array
{
    return array_keys(paymentTermDefinitions());
}

function isValidPaymentTerm(?string $term): bool
{
    return in_array(strtolower(trim((string) $term)), validPaymentTerms(), true);
}

function paymentTermLabel(?string $term): string
{
    $key = strtolower(trim((string) $term));
    return paymentTermDefinitions()[$key]['label'] ?? ($key !== '' ? $key : 'Due on Receipt');
}

function paymentTermDays(?string $term): int
{
    $key = strtolower(trim((string) $term));
    return (int) (paymentTermDefinitions()[$key]['days'] ?? 0);
}

function calculatePaymentDueDate(string $issueDate, ?string $term): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $issueDate);
    if (!$date || $date->format('Y-m-d') !== $issueDate) {
        throw new InvalidArgumentException('Invalid issue date.');
    }

    $days = paymentTermDays($term);
    return $days > 0 ? $date->modify("+{$days} days")->format('Y-m-d') : $issueDate;
}
