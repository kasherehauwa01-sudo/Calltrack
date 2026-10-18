package com.example.calltrack.notification

import android.content.Context
import android.util.Log
import com.example.calltrack.BuildConfig
import com.example.calltrack.data.repository.ClientPhoneNumbers
import kotlinx.coroutines.CancellationException

/** Optional phone enrichment, invoked only from the message store's IO coroutine. No cache/network/logs. */
internal class MaxMessagePhoneResolver(private val lookup: (String) -> ResolvedContact) {
    constructor(context: Context) : this({ name -> MaxContactResolver(context).resolveMessage(name) })

    fun resolve(name: String): String {
        if (BuildConfig.DEBUG) Log.d(PHONE_DEBUG_TAG, "resolve MAX name=[$name] normalizedName=[${MaxContactNameMatcher.normalize(name)}]")
        if (name.isBlank()) {
            if (BuildConfig.DEBUG) Log.d(PHONE_DEBUG_TAG, "final status=NOT_FOUND phoneToLocalStore=[] reason=blankName")
            return ""
        }
        return try {
            val result = lookup(name)
            val phone = if (result.status == ContactResolutionStatus.RESOLVED) result.phone.orEmpty() else ""
            if (BuildConfig.DEBUG) Log.d(PHONE_DEBUG_TAG, "final status=${result.status} resolvedPhone=[${result.phone}] phoneToLocalStore=[$phone]")
            phone
        } catch (cancelled: CancellationException) {
            if (BuildConfig.DEBUG) Log.w(PHONE_DEBUG_TAG, "resolve exceptionType=${cancelled.javaClass.name} message=${cancelled.message} cancelled=true")
            throw cancelled
        } catch (error: Exception) {
            if (BuildConfig.DEBUG) Log.w(PHONE_DEBUG_TAG, "resolve exceptionType=${error.javaClass.name} message=${error.message} final status=ERROR phoneToLocalStore=[]")
            // Permission/provider failures are optional-enrichment failures, never event failures.
            ""
        }
    }

    companion object {
        internal const val PHONE_DEBUG_TAG = "CalltrackMAXPhoneDebug"
        internal fun resolveCandidates(matches: List<ContactCandidate>): ResolvedContact {
            val contacts = matches.groupBy { it.id }.map { (id, rows) ->
                ContactCandidate(id, rows.flatMap { it.phones }.map(ClientPhoneNumbers::fromRussianContact).distinct())
            }
            if (contacts.any { "" in it.phones }) return ResolvedContact(null, ContactResolutionStatus.ERROR)
            return ContactResolutionRules.resolve(contacts)
        }
    }
}
