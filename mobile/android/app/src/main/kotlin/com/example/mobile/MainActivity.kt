package com.example.mobile

import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import android.app.Activity
import android.content.Intent
import java.io.ByteArrayOutputStream

class MainActivity : FlutterActivity() {
    private var pendingPhoto: MethodChannel.Result? = null
    private val photoRequest = 4712

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "assocmap/member_photo")
            .setMethodCallHandler { call, result ->
                if (call.method != "pick") {
                    result.notImplemented()
                } else if (pendingPhoto != null) {
                    result.error("busy", "A photo selection is already open.", null)
                } else {
                    pendingPhoto = result
                    try {
                        val intent = Intent(Intent.ACTION_OPEN_DOCUMENT).apply {
                            addCategory(Intent.CATEGORY_OPENABLE)
                            type = "image/*"
                            putExtra(Intent.EXTRA_MIME_TYPES, arrayOf("image/jpeg", "image/png"))
                        }
                        startActivityForResult(intent, photoRequest)
                    } catch (_: Exception) {
                        pendingPhoto = null
                        result.error("unavailable", "Photo selection is unavailable.", null)
                    }
                }
            }
    }

    @Deprecated("Android activity result bridge")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode != photoRequest) return
        val result = pendingPhoto ?: return
        pendingPhoto = null
        val uri = data?.data
        if (resultCode != Activity.RESULT_OK || uri == null) {
            result.success(null)
            return
        }
        // Read only the selected document; no broad storage permission or retained URI.
        Thread {
            try {
                val bytes = contentResolver.openInputStream(uri)?.use { source ->
                    val output = ByteArrayOutputStream()
                    val buffer = ByteArray(8192)
                    while (true) {
                        val read = source.read(buffer)
                        if (read < 0) break
                        if (output.size() + read > 2 * 1024 * 1024) throw IllegalArgumentException()
                        output.write(buffer, 0, read)
                    }
                    output.toByteArray()
                } ?: throw IllegalArgumentException()
                runOnUiThread { result.success(bytes) }
            } catch (_: Exception) {
                runOnUiThread { result.error("invalid_photo", "Choose a readable JPEG or PNG photo of at most 2 MiB.", null) }
            }
        }.start()
    }
}
