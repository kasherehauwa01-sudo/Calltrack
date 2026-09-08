package com.example.calltrack.ui.auth

import android.content.Intent
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.example.calltrack.auth.AndroidAuthClient
import com.example.calltrack.databinding.ActivityLoginBinding
import com.example.calltrack.ui.main.MainActivity
import kotlinx.coroutines.launch

class LoginActivity:AppCompatActivity(){
    private lateinit var binding:ActivityLoginBinding
    override fun onCreate(savedInstanceState:Bundle?){super.onCreate(savedInstanceState);binding=ActivityLoginBinding.inflate(layoutInflater);setContentView(binding.root)
        val auth=AndroidAuthClient(this)
        if(authStoreHasToken())lifecycleScope.launch{if(auth.validate())openApp()else auth.logout()}
        binding.loginButton.setOnClickListener{val login=binding.loginEmail.text.toString().trim();val pin=binding.loginPin.text.toString();binding.loginStatus.text=""
            lifecycleScope.launch{binding.loginButton.isEnabled=false;auth.login(login,pin).onSuccess{openApp()}.onFailure{binding.loginStatus.text=it.message};binding.loginButton.isEnabled=true}}
    }
    private fun authStoreHasToken()=com.example.calltrack.auth.AuthStore(this).isAuthenticated
    private fun openApp(){startActivity(Intent(this,MainActivity::class.java));finish()}
}
