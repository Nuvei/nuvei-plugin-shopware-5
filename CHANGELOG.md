# ShopWare 5 Nuvei Module

---

# 2.0.1
```
    * Added sourceApplication parameter.
    * Added the plugin version into webMasterId parameter.
    * Fix for Settle and Void actions.
    * Added missing logic for Refund DMNs.
    * New domain for Sandbox endpoints.
    * Fixed plugin icon.
    * Return code 400 to the Cashier, when the plugin did not find an OC Order nby the DMN data.
    * Implemented Auto-Void.
    * Trim the merchant credentials after get them.
    * Set different delay time in the DMN logic according the environment.
    * Removed Nuvei Partially Refunded status because it is missing in SW system.
    * Do not call Nuvei admin scripts if the Order does not belongs to Nuvei.
```

# 2.0.0
```
    * Use Nuvei instead of Safecharge into the file names.
    * Use new custom table for Nuvei orders.
    * Added changelog and readme files.
```