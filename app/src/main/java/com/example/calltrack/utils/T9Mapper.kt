package com.example.calltrack.utils

object T9Mapper {

    private val map = mapOf(
        'A' to '2', 'B' to '2', 'C' to '2',
        'А' to '2', 'Б' to '2', 'В' to '2', 'Г' to '2',

        'D' to '3', 'E' to '3', 'F' to '3',
        'Д' to '3', 'Е' to '3', 'Ё' to '3', 'Ж' to '3', 'З' to '3',

        'G' to '4', 'H' to '4', 'I' to '4',
        'И' to '4', 'Й' to '4', 'К' to '4', 'Л' to '4',

        'J' to '5', 'K' to '5', 'L' to '5',
        'М' to '5', 'Н' to '5', 'О' to '5', 'П' to '5',

        'M' to '6', 'N' to '6', 'O' to '6',
        'Р' to '6', 'С' to '6', 'Т' to '6', 'У' to '6',

        'P' to '7', 'Q' to '7', 'R' to '7', 'S' to '7',
        'Ф' to '7', 'Х' to '7', 'Ц' to '7', 'Ч' to '7',

        'T' to '8', 'U' to '8', 'V' to '8',
        'Ш' to '8', 'Щ' to '8', 'Ъ' to '8', 'Ы' to '8',

        'W' to '9', 'X' to '9', 'Y' to '9', 'Z' to '9',
        'Ь' to '9', 'Э' to '9', 'Ю' to '9', 'Я' to '9'
    )

    fun nameToDigits(input: String): String {
        return buildString {
            input.uppercase().forEach { ch ->
                map[ch]?.let { append(it) }
            }
        }
    }
}
