package com.example.calltrack.auth

import android.content.Context
import okhttp3.Interceptor
import okhttp3.Response

class AuthInterceptor(context: Context): Interceptor {
    // Не открываем Android Keystore во время создания App/CallRepository.
    // Первичное восстановление auth-хранилища выполняет экран авторизации.
    private val store by lazy(LazyThreadSafetyMode.SYNCHRONIZED) { AuthStore(context.applicationContext) }
    override fun intercept(chain: Interceptor.Chain): Response {
        val token=store.token
        val request=if(token.isBlank())chain.request() else chain.request().newBuilder().header("Authorization","Bearer $token").build()
        val response=chain.proceed(request)
        if(response.code==401&&token.isNotBlank())store.clear()
        return response
    }
}
