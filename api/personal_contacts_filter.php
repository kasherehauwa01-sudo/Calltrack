<?php
declare(strict_types=1);

function personalPhoneSql(string $column): string
{
    // Same last-ten-digits normalization as Clients/Android; never match blanks.
    $digits="REGEXP_REPLACE(COALESCE($column,''), '[^0-9]', '')";
    return "(CASE WHEN CHAR_LENGTH($digits)>=10 THEN RIGHT($digits,10) ELSE NULL END)";
}

function workingCallsSql(string $alias='calls'): string
{
    $callPhone=personalPhoneSql($alias.'.phone');
    $personalPhone=personalPhoneSql('pc.contact_phone');
    return "COALESCE($alias.client,'') <> 'Личный звонок' AND NOT EXISTS (SELECT 1 FROM personal_contacts pc WHERE BINARY pc.user_phone=BINARY $alias.user_phone AND pc.personal_flag=1 AND BINARY $personalPhone=BINARY $callPhone)";
}
