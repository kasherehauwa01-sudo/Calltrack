package com.example.calltrack.ui.base

import android.app.Dialog
import android.view.View
import android.view.ViewGroup
import android.view.ViewTreeObserver
import android.widget.TextView

internal class MojibakeViewRepair(private val root: View) : ViewTreeObserver.OnPreDrawListener {
    fun start() {
        root.viewTreeObserver.addOnPreDrawListener(this)
        repairTree(root)
    }

    fun stop() {
        if (root.viewTreeObserver.isAlive) root.viewTreeObserver.removeOnPreDrawListener(this)
    }

    override fun onPreDraw(): Boolean {
        repairTree(root)
        return true
    }

    private fun repairTree(view: View) {
        if (view is TextView) {
            val repaired = TextEncoding.repair(view.text)
            if (repaired.toString() != view.text.toString()) view.text = repaired
            view.hint?.let { hint ->
                val repairedHint = TextEncoding.repair(hint)
                if (repairedHint.toString() != hint.toString()) view.hint = repairedHint
            }
        }
        if (view is ViewGroup) {
            for (index in 0 until view.childCount) repairTree(view.getChildAt(index))
        }
    }
}

internal fun Dialog.installMojibakeRepair() {
    val install = {
        window?.decorView?.let { MojibakeViewRepair(it).start() }
    }
    if (isShowing) install() else setOnShowListener { install() }
}
