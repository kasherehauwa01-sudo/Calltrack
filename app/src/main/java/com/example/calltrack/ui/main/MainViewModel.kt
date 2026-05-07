package com.example.calltrack.ui.main

import androidx.lifecycle.LiveData
import androidx.lifecycle.MutableLiveData
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.asLiveData
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.data.local.CallHistoryEntity
import com.example.calltrack.data.local.CommentEntity
import com.example.calltrack.data.local.ContactEntity
import com.example.calltrack.data.local.ReminderEntity
import com.example.calltrack.data.remote.CallHistoryItem
import com.example.calltrack.data.repository.CallRepository

class MainViewModel(private val repository: CallRepository) : ViewModel() {
    val calls = repository.observeCalls().asLiveData()
    val onboardingCompleted = repository.prefs.onboardingCompleted.asLiveData()

    private val _dialNumber = MutableLiveData("")
    val dialNumber: LiveData<String> = _dialNumber

    fun setDialNumber(number: String) {
        _dialNumber.value = number
    }

    fun observeContact(phone: String): LiveData<ContactEntity?> = repository.observeContact(phone).asLiveData()
    fun observeCallsByPhone(phone: String): LiveData<List<CallEntity>> = repository.observeCallsByPhone(phone).asLiveData()
    fun observeReminders(phone: String): LiveData<List<ReminderEntity>> = repository.observeReminders(phone).asLiveData()
    fun observeComments(phone: String): LiveData<List<CommentEntity>> = repository.observeComments(phone).asLiveData()
    fun findClientName(phone: String): String = repository.findClientName(phone)

    suspend fun saveCallOutcome(
        callId: Long,
        phone: String,
        contactName: String,
        tag: String,
        reminderMillis: Long?,
        note: String,
        reminderMessage: String = "Перезвонить"
    ) = repository.saveCallOutcome(callId, phone, contactName, tag, reminderMillis, note, reminderMessage)



    suspend fun saveCommentForCall(callId: Long, phone: String, text: String) =
        repository.saveCommentForCall(callId, phone, text)

    suspend fun saveReminderForCall(callId: Long, phone: String, contactName: String, text: String, remindAt: Long) =
        repository.saveReminderForCall(callId, phone, contactName, text, remindAt)

    suspend fun addComment(phone: String, text: String) = repository.addComment(phone, text)
    suspend fun addReminder(phone: String, contactName: String, text: String, remindAt: Long) =
        repository.addReminder(phone, contactName, text, remindAt)

    suspend fun markOnboardingCompleted() = repository.prefs.setOnboardingCompleted(true)
    suspend fun setManagerName(name: String) = repository.prefs.setManagerName(name)
    // Синхронизацию выполняем только после сохранения результата звонка (saveCallOutcome).
    suspend fun sync() = Unit
    suspend fun loadHistoryFromRemote(phone: String): List<CallHistoryItem> = repository.loadHistoryFromRemote(phone)
    suspend fun getHistory(phone: String): List<CallHistoryEntity> = repository.getHistory(phone)
    suspend fun refreshHistory(phone: String) = repository.refreshHistory(phone)

    class Factory(private val repository: CallRepository) : ViewModelProvider.Factory {
        override fun <T : ViewModel> create(modelClass: Class<T>): T {
            return MainViewModel(repository) as T
        }
    }
}
