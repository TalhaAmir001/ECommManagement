<?php

namespace App\Http\Controllers;

use App\Jobs\SyncCourierProviderJob;
use App\Models\CourierProvider as CourierProviderModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CourierProviderController extends Controller
{
    /**
     * Toggle a provider on/off. Manual is intentionally not toggleable here
     * — the admin always has the manual fallback available.
     */
    public function toggle(Request $request, CourierProviderModel $provider): RedirectResponse
    {
        if ($provider->key === 'manual') {
            return back()->withErrors(['status' => 'The manual provider is always available.']);
        }

        $provider->forceFill(['enabled' => ! $provider->enabled])->save();

        return back()->with('status', $provider->display_name.' is now '.($provider->enabled ? 'enabled' : 'disabled').'.');
    }

    /**
     * Force a sync of one provider right now (queues the job).
     */
    public function syncNow(CourierProviderModel $provider): RedirectResponse
    {
        SyncCourierProviderJob::dispatch($provider->id);

        return back()->with('status', 'Sync queued for '.$provider->display_name.'.');
    }
}
