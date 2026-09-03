package com.example.calltrack.ui.scanner

import android.os.Bundle
import com.example.calltrack.R
import com.example.calltrack.databinding.ActivityBarcodeScannerBinding
import com.google.zxing.BarcodeFormat
import com.google.zxing.client.android.Intents
import com.journeyapps.barcodescanner.CaptureManager
import com.example.calltrack.ui.base.BaseActivity

class BarcodeScannerActivity : BaseActivity() {
    private lateinit var binding: ActivityBarcodeScannerBinding
    private lateinit var capture: CaptureManager
    private var torchEnabled = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityBarcodeScannerBinding.inflate(layoutInflater)
        setContentView(binding.root)

        intent.putExtra(Intents.Scan.FORMATS, BarcodeFormat.EAN_13.name)
        intent.putExtra(Intents.Scan.PROMPT_MESSAGE, getString(R.string.scanner_hint))
        intent.putExtra(Intents.Scan.BEEP_ENABLED, false)
        capture = CaptureManager(this, binding.barcodeView)
        capture.initializeFromIntent(intent, savedInstanceState)
        capture.decode()

        binding.btnTorch.setOnClickListener {
            torchEnabled = !torchEnabled
            if (torchEnabled) binding.barcodeView.setTorchOn() else binding.barcodeView.setTorchOff()
            binding.btnTorch.alpha = if (torchEnabled) 1f else 0.65f
        }
    }

    override fun onResume() { super.onResume(); capture.onResume() }
    override fun onPause() { capture.onPause(); super.onPause() }
    override fun onDestroy() { capture.onDestroy(); super.onDestroy() }
    override fun onSaveInstanceState(outState: Bundle) { super.onSaveInstanceState(outState); capture.onSaveInstanceState(outState) }
}
