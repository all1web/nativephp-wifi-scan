package com.wifiscan.mobile

import android.Manifest
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.location.LocationManager
import android.net.wifi.ScanResult
import android.net.wifi.WifiManager
import android.os.Build
import android.util.Log
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONArray
import org.json.JSONObject
import java.lang.ref.WeakReference

/**
 * Bridge functions exposing WiFi visibility to PHP.
 * Namespace: "Wifi.*" — registered in nativephp.json.
 *
 * All functions are FOREGROUND-ONLY by design. Android throttles WifiManager
 * scans hard in the background (1 scan / 30 min for background apps, and no
 * synchronous results ever), and reading scan results / the connected SSID
 * requires a location or NEARBY_WIFI_DEVICES grant that is only meaningful with
 * an Activity on screen. See docs/DESIGN.md for the full rationale.
 */
object WifiFunctions {

    private const val TAG = "WifiScan"

    // PHP event FQCNs. Backslashes are escaped for the Kotlin string literal;
    // NativeActionCoordinator re-escapes as needed for the JS/HTTP dispatch.
    private const val EVENT_NETWORKS_SCANNED = "WifiScan\\Mobile\\Events\\NetworksScanned"
    private const val EVENT_SCAN_FAILED = "WifiScan\\Mobile\\Events\\ScanFailed"
    private const val EVENT_PERMISSION_GRANTED = "WifiScan\\Mobile\\Events\\PermissionGranted"
    private const val EVENT_PERMISSION_DENIED = "WifiScan\\Mobile\\Events\\PermissionDenied"

    private const val PERMISSION_REQUEST_CODE = 7311

    /** Holds a weak reference to the current activity for event dispatch. */
    object ActivityHolder {
        private var ref: WeakReference<FragmentActivity>? = null
        fun set(activity: FragmentActivity) { ref = WeakReference(activity) }
        fun get(): FragmentActivity? = ref?.get()
    }

    // -----------------------------------------------------------------------
    // Permission model
    // -----------------------------------------------------------------------

    /**
     * The single runtime permission we ask for on this device. On API 33+ the
     * dedicated NEARBY_WIFI_DEVICES permission covers scanning; on older devices
     * scan results are gated behind fine location.
     */
    private fun requiredPermission(): String =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            Manifest.permission.NEARBY_WIFI_DEVICES
        } else {
            Manifest.permission.ACCESS_FINE_LOCATION
        }

    /**
     * True when the app currently holds a permission that lets it read scan
     * results. Either NEARBY_WIFI_DEVICES (33+) or fine location satisfies the
     * platform, so we accept whichever is granted.
     */
    private fun hasScanPermission(context: Context): Boolean {
        val fine = ContextCompat.checkSelfPermission(
            context, Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED

        val nearby = Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(
                context, Manifest.permission.NEARBY_WIFI_DEVICES
            ) == PackageManager.PERMISSION_GRANTED

        return fine || nearby
    }

    /**
     * On API 28+ scan results are also gated behind location services being on,
     * even when the permission is granted. Surface it so PHP can prompt sanely.
     */
    private fun locationServicesEnabled(context: Context): Boolean {
        val lm = context.getSystemService(Context.LOCATION_SERVICE) as? LocationManager ?: return true
        return lm.isProviderEnabled(LocationManager.GPS_PROVIDER) ||
            lm.isProviderEnabled(LocationManager.NETWORK_PROVIDER)
    }

    // -----------------------------------------------------------------------
    // Serialization
    // -----------------------------------------------------------------------

    @Suppress("DEPRECATION")
    private fun ssidOf(result: ScanResult): String {
        val raw = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            result.wifiSsid?.toString() ?: ""
        } else {
            result.SSID ?: ""
        }
        // API 33 wifiSsid.toString() wraps the value in quotes; strip them.
        return raw.trim('"')
    }

    private fun scanResultsToJson(results: List<ScanResult>): JSONArray {
        // Strongest signal first — most useful nearest radios lead the list.
        val sorted = results.sortedByDescending { it.level }
        val array = JSONArray()
        for (r in sorted) {
            array.put(JSONObject().apply {
                put("ssid", ssidOf(r))
                put("bssid", r.BSSID ?: "")
                put("rssi", r.level)
                put("frequency", r.frequency)
            })
        }
        return array
    }

    // -----------------------------------------------------------------------
    // Event dispatch
    // -----------------------------------------------------------------------

    private fun dispatchEvent(activity: FragmentActivity, event: String, payloadJson: String) {
        try {
            activity.runOnUiThread {
                try {
                    NativeActionCoordinator.dispatchEvent(activity, event, payloadJson)
                } catch (e: Exception) {
                    Log.e(TAG, "Error dispatching $event on UI thread: ${e.message}", e)
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "Error dispatching $event: ${e.message}", e)
        }
    }

    // =======================================================================
    // Bridge Functions
    // =======================================================================

    /**
     * Return the last cached scan synchronously and kick off a fresh one.
     * Fresh results are delivered later via the NetworksScanned event.
     */
    class Scan(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            ActivityHolder.set(activity)
            val context = activity.applicationContext

            if (!hasScanPermission(context)) {
                dispatchEvent(activity, EVENT_SCAN_FAILED, JSONObject().put("reason", "permission").toString())
                return BridgeResponse.error("PERMISSION_DENIED", "Missing scan permission (${requiredPermission()})")
            }

            val wifi = context.getSystemService(Context.WIFI_SERVICE) as? WifiManager
                ?: return BridgeResponse.error("EXECUTION_FAILED", "WifiManager unavailable")

            if (!wifi.isWifiEnabled) {
                dispatchEvent(activity, EVENT_SCAN_FAILED, JSONObject().put("reason", "wifi_disabled").toString())
                return BridgeResponse.error("WIFI_DISABLED", "WiFi is disabled")
            }

            // Cached last-known results, returned immediately.
            @Suppress("DEPRECATION")
            val cached = try {
                wifi.scanResults ?: emptyList()
            } catch (e: SecurityException) {
                dispatchEvent(activity, EVENT_SCAN_FAILED, JSONObject().put("reason", "permission").toString())
                return BridgeResponse.error("PERMISSION_DENIED", "SecurityException reading scanResults: ${e.message}")
            }
            val cachedJson = scanResultsToJson(cached)

            // Register a one-shot receiver for fresh results, then request a scan.
            registerScanReceiver(activity, wifi)

            @Suppress("DEPRECATION")
            val started = try {
                wifi.startScan()
            } catch (e: Exception) {
                Log.w(TAG, "startScan() threw (likely throttled): ${e.message}")
                false
            }
            if (!started) {
                // Throttled or refused — the cached list is still valid; signal it.
                Log.d(TAG, "startScan() returned false (throttled); serving cached results")
            }

            return BridgeResponse.success(
                mapOf(
                    "networks" to cachedJson.toString(),
                    "count" to cachedJson.length(),
                    "fromCache" to true,
                    "scanRequested" to started
                )
            )
        }

        private fun registerScanReceiver(activity: FragmentActivity, wifi: WifiManager) {
            val appContext = activity.applicationContext
            val receiver = object : BroadcastReceiver() {
                override fun onReceive(ctx: Context, intent: Intent) {
                    try {
                        appContext.unregisterReceiver(this)
                    } catch (_: IllegalArgumentException) {
                        // already unregistered
                    }
                    val fresh = try {
                        @Suppress("DEPRECATION")
                        wifi.scanResults ?: emptyList()
                    } catch (e: SecurityException) {
                        Log.e(TAG, "SecurityException reading fresh scanResults: ${e.message}")
                        return
                    }
                    val json = scanResultsToJson(fresh)
                    val payload = JSONObject()
                        .put("networks", json)
                        .put("count", json.length())
                    ActivityHolder.get()?.let { live ->
                        dispatchEvent(live, EVENT_NETWORKS_SCANNED, payload.toString())
                    } ?: Log.d(TAG, "No live activity to dispatch NetworksScanned; dropping (foreground-only)")
                }
            }
            val filter = IntentFilter(WifiManager.SCAN_RESULTS_AVAILABLE_ACTION)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                appContext.registerReceiver(receiver, filter, Context.RECEIVER_NOT_EXPORTED)
            } else {
                @Suppress("UnspecifiedRegisterReceiverFlag")
                appContext.registerReceiver(receiver, filter)
            }
        }
    }

    /** Read the currently connected access point. */
    class Current(private val activity: FragmentActivity) : BridgeFunction {
        @Suppress("DEPRECATION")
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            ActivityHolder.set(activity)
            val context = activity.applicationContext

            val wifi = context.getSystemService(Context.WIFI_SERVICE) as? WifiManager
                ?: return BridgeResponse.error("EXECUTION_FAILED", "WifiManager unavailable")

            val info = wifi.connectionInfo
                ?: return BridgeResponse.success(mapOf("connected" to false))

            // BSSID is null / all-zero when not associated. SSID reads as
            // "<unknown ssid>" without a location grant.
            val bssid = info.bssid
            val connected = bssid != null && bssid != "02:00:00:00:00:00" && bssid != "00:00:00:00:00:00"

            if (!connected) {
                return BridgeResponse.success(mapOf("connected" to false))
            }

            val ssid = (info.ssid ?: "").trim('"')

            return BridgeResponse.success(
                mapOf(
                    "connected" to true,
                    "ssid" to ssid,
                    "bssid" to bssid,
                    "rssi" to info.rssi,
                    "frequency" to (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) info.frequency else 0)
                )
            )
        }
    }

    /** Report the current scan-permission status. */
    class CheckPermission(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            ActivityHolder.set(activity)
            val context = activity.applicationContext
            val granted = hasScanPermission(context)
            return BridgeResponse.success(
                mapOf(
                    "status" to if (granted) "granted" else "denied",
                    "requiredPermission" to requiredPermission(),
                    "locationServicesEnabled" to locationServicesEnabled(context)
                )
            )
        }
    }

    /**
     * Request the scan permission. If already held, dispatches PermissionGranted
     * immediately. Otherwise shows the system dialog and returns "pending" — the
     * host Activity's onRequestPermissionsResult drives the final grant/deny
     * event (see docs/REFERENCE.md "Delivering the permission result").
     */
    class RequestPermission(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            ActivityHolder.set(activity)
            val context = activity.applicationContext

            if (hasScanPermission(context)) {
                dispatchEvent(activity, EVENT_PERMISSION_GRANTED,
                    JSONObject().put("permission", requiredPermission()).toString())
                return BridgeResponse.success(mapOf("granted" to true, "status" to "granted"))
            }

            val perms = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                arrayOf(Manifest.permission.NEARBY_WIFI_DEVICES, Manifest.permission.ACCESS_FINE_LOCATION)
            } else {
                arrayOf(Manifest.permission.ACCESS_FINE_LOCATION)
            }

            return try {
                activity.runOnUiThread {
                    activity.requestPermissions(perms, PERMISSION_REQUEST_CODE)
                }
                BridgeResponse.success(mapOf("granted" to false, "status" to "pending"))
            } catch (e: Exception) {
                Log.e(TAG, "Error requesting permission: ${e.message}", e)
                dispatchEvent(activity, EVENT_PERMISSION_DENIED,
                    JSONObject().put("permission", requiredPermission()).toString())
                BridgeResponse.success(mapOf("granted" to false, "status" to "denied"))
            }
        }
    }
}
