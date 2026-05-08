package com.example.calltrack.ui.main

import android.os.Bundle
import com.example.calltrack.BuildConfig
import com.example.calltrack.databinding.ActivityAboutBinding
import com.example.calltrack.ui.base.BaseActivity

class AboutActivity : BaseActivity() {

    private lateinit var binding: ActivityAboutBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityAboutBinding.inflate(layoutInflater)
        setContentView(binding.root)
        applyInsets(binding.root, binding.statusBarOverlay)

        binding.tvVersionValue.text = BuildConfig.VERSION_NAME
        binding.tvReleaseDateValue.text = BuildConfig.APP_RELEASE_DATE

        binding.btnBack.setOnClickListener { finish() }
    }
}
