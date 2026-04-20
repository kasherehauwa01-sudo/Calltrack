package com.example.calltrack.ui.main

import androidx.lifecycle.LiveData
import androidx.lifecycle.MutableLiveData
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.asLiveData
import com.example.calltrack.data.repository.CallRepository

class MainViewModel(private val repository: CallRepository) : ViewModel() {
    val calls = repository.observeCalls().asLiveData()
    val onboardingCompleted = repository.prefs.onboardingCompleted.asLiveData()

    private val _dialNumber = MutableLiveData("")
    val dialNumber: LiveData<String> = _dialNumber

    fun setDialNumber(number: String) {
        _dialNumber.value = number
    }

    suspend fun markOnboardingCompleted() = repository.prefs.setOnboardingCompleted(true)
    suspend fun sync() = repository.syncPending()

    class Factory(private val repository: CallRepository) : ViewModelProvider.Factory {
        override fun <T : ViewModel> create(modelClass: Class<T>): T {
            return MainViewModel(repository) as T
        }
    }
}
