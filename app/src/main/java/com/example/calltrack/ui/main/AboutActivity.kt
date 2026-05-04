package com.example.calltrack.ui.main

import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import com.example.calltrack.BuildConfig
import com.example.calltrack.databinding.ActivityAboutBinding

class AboutActivity : AppCompatActivity() {

    private lateinit var binding: ActivityAboutBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityAboutBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.tvVersionValue.text = BuildConfig.VERSION_NAME
        binding.tvReleaseDateValue.text = BuildConfig.APP_RELEASE_DATE

        binding.btnBack.setOnClickListener { finish() }
    }
}
