package com.example.calltrack.notification

import android.content.Context
import com.example.calltrack.data.repository.ClientPhoneNumbers
import kotlinx.coroutines.CancellationException

/** Optional phone enrichment, invoked only from the message store's IO coroutine. No cache/network/logs. */
internal class MaxMessagePhoneResolver(private val lookup: (String) -> ResolvedContact) {
    constructor(context: Context) : this({ name -> MaxContactResolver(context).resolveMessage(name) })

    fun resolve(name: String): String {
        if (name.isBlank()) return ""
        return try {
            val result = lookup(name)
            if (result.status == ContactResolutionStatus.RESOLVED) result.phone.orEmpty() else ""
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (_: Exception) {
            // Permission/provider failures are optional-enrichment failures, never event failures.
            ""
        }
    }

    companion object {
        internal fun resolveCandidates(matches: List<ContactCandidate>): ResolvedContact {
            val contacts = matches.groupBy { it.id }.map { (id, rows) ->
                ContactCandidate(id, rows.flatMap { it.phones }.map(ClientPhoneNumbers::fromRussianContact).distinct())
            }
            if (contacts.any { "" in it.phones }) return ResolvedContact(null, ContactResolutionStatus.ERROR)
            return ContactResolutionRules.resolve(contacts)
        }
    }
}
