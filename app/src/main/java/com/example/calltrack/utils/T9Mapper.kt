package com.example.calltrack.utils

object T9Mapper {

    private val map = mapOf(
        'A' to '2', 'B' to '2', 'C' to '2',
        '\u0410' to '2', '\u0411' to '2', '\u0412' to '2', '\u0413' to '2',

        'D' to '3', 'E' to '3', 'F' to '3',
        '\u0414' to '3', '\u0415' to '3', '\u0401' to '3', '\u0416' to '3', '\u0417' to '3',

        'G' to '4', 'H' to '4', 'I' to '4',
        '\u0418' to '4', '\u0419' to '4', '\u041A' to '4', '\u041B' to '4',

        'J' to '5', 'K' to '5', 'L' to '5',
        '\u041C' to '5', '\u041D' to '5', '\u041E' to '5', '\u041F' to '5',

        'M' to '6', 'N' to '6', 'O' to '6',
        '\u0420' to '6', '\u0421' to '6', '\u0422' to '6', '\u0423' to '6',

        'P' to '7', 'Q' to '7', 'R' to '7', 'S' to '7',
        '\u0424' to '7', '\u0425' to '7', '\u0426' to '7', '\u0427' to '7',

        'T' to '8', 'U' to '8', 'V' to '8',
        '\u0428' to '8', '\u0429' to '8', '\u042A' to '8', '\u042B' to '8',

        'W' to '9', 'X' to '9', 'Y' to '9', 'Z' to '9',
        '\u042C' to '9', '\u042D' to '9', '\u042E' to '9', '\u042F' to '9'
    )

    fun nameToDigits(input: String): String {
        return buildString {
            input.uppercase().forEach { ch ->
                map[ch]?.let { append(it) }
            }
        }
    }
}
