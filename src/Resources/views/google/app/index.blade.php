<x-admin::layouts>
    <!-- Title -->
    <x-slot:title>
        @lang('google::app.app.index.title')
    </x-slot>

    <div class="box-shadow rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900 p-6">
        <!-- Add / Update Google App Configuration -->
        <x-admin::form :action="route('admin.google.app.store')" method="POST">
            <x-admin::form.control-group>
                <x-admin::form.control-group.label class="required">
                    @lang('google::app.app.index.client-id')
                </x-admin::form.control-group.label>

                <x-admin::form.control-group.control type="text" name="client_id" :value="$googleApp->client_id ?? ''" rules="required" />
            </x-admin::form.control-group>

            <x-admin::form.control-group>
                <x-admin::form.control-group.label class="required">
                    @lang('google::app.app.index.client-secret')
                </x-admin::form.control-group.label>

                <x-admin::form.control-group.control type="text" name="client_secret" :value="$googleApp->client_secret ?? ''"
                    rules="required" />
            </x-admin::form.control-group>

            <x-admin::form.control-group>
                <x-admin::form.control-group.label>
                    @lang('google::app.app.index.redirect-uri')
                </x-admin::form.control-group.label>

                <x-admin::form.control-group.control type="text" name="redirect_uri" :value="$googleApp->redirect_uri ?? ''" />
            </x-admin::form.control-group>

            <x-admin::form.control-group>
                <x-admin::form.control-group.label>
                    @lang('google::app.app.index.webhook-uri')
                </x-admin::form.control-group.label>

                <x-admin::form.control-group.control type="text" name="webhook_uri" :value="$googleApp->webhook_uri ?? ''" />
            </x-admin::form.control-group>

            <x-admin::form.control-group>
                <x-admin::form.control-group.label>
                    @lang('google::app.app.index.scopes')
                </x-admin::form.control-group.label>

                <x-admin::form.control-group.control type="text" name="scopes" :value="is_array($googleApp->scopes) ? implode(',', $googleApp->scopes) : ''"
                    placeholder="calendar,meet" />

            </x-admin::form.control-group>

            <button type="submit" class="primary-button mt-4">
                @lang('google::app.app.index.save')
            </button>
        </x-admin::form>

        @if ($googleApp)
            <!-- Remove Configuration -->
            <form action="{{ route('admin.google.app.destroy', $googleApp->id) }}" method="POST" class="mt-4">
                @csrf
                @method('DELETE')

                <button type="submit" class="text-red-500 hover:underline">
                    @lang('google::app.app.index.remove')
                </button>
            </form>
        @endif
    </div>
</x-admin::layouts>
