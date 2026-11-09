<div class="stack">
    <p>Edit the information customers see on the shop page. Confirm business details with the owner before removing their pending or demonstration labels.</p>
    <a class="button button-secondary" href="{{ route('storefront.index') }}">Preview customer page</a>
    @php
        $groups=[
            'Shop identity'=>['display_name'=>['Shop display name',160,false],'about'=>['Short shop introduction',1000,true]],
            'Shop background'=>['history'=>['Shop history',2000,true],'mission'=>['Mission',1000,true],'vision'=>['Vision',1000,true],'goals'=>['Customer-facing goals',2000,true]],
            'Visit information'=>['address'=>['Shop address',1000,true],'hours'=>['Opening hours',1000,true]],
            'Contact options'=>['phone'=>['Official phone number',16,false],'messenger_page'=>['Messenger page username',100,false]],
            'Services'=>['services'=>['Service listings',4000,true]],
        ];
    @endphp
    @foreach($groups as $heading=>$fields)
        <section class="stack storefront-settings-group" aria-labelledby="storefront-group-{{ $loop->index }}">
            <h3 id="storefront-group-{{ $loop->index }}">{{ $heading }}</h3>
            @if($heading==='Shop background')<p>The initial history, mission, vision and customer-facing goals come from Milestone 1, pages 2–3. Leave an optional field blank to hide that part of the profile.</p>@endif
            @foreach($fields as $key=>[$label,$limit,$multiline])
                <div class="field">
                    <label for="storefront-{{ $key }}">{{ $label }}</label>
                    @if($multiline)<textarea id="storefront-{{ $key }}" name="values[{{ $key }}]" rows="{{ in_array($key,['history','services'])?5:3 }}" maxlength="{{ $limit }}" @if($key==='goals') aria-describedby="storefront-goals-help" @endif>{{ old('values.'.$key,$values[$key]) }}</textarea>
                    @else<input class="form-input" id="storefront-{{ $key }}" name="values[{{ $key }}]" value="{{ old('values.'.$key,$values[$key]) }}" maxlength="{{ $limit }}">@endif
                    @if($key==='phone')<p>Leave blank until confirmed. Use international format, for example +63 followed by the number.</p>
                    @elseif($key==='messenger_page')<p>Enter only the confirmed page username, not a full URL. Leave blank until available. Messenger requires internet.</p>
                    @elseif($key==='goals')<p id="storefront-goals-help">One goal per line. Include goals relevant to customers; internal sales and inventory objectives belong in the project documentation.</p>
                    @elseif($key==='services')<p>One service per line: Service name | Description. Up to 20 lines; names up to 200 characters. An empty list hides all service listings. These are inquiry listings, with no online booking or payment.</p>@endif
                </div>
            @endforeach
            @if($heading==='Shop background')
                <input type="hidden" name="values[profile_pending]" value="0">
                <label class="settings-checkbox"><input type="checkbox" name="values[profile_pending]" value="1" @checked(old('values.profile_pending',$values['profile_pending']))> Shop background and statements await owner confirmation</label>
                <p>Keep this checked until the owner confirms the history, mission, vision and goals. This label is separate from demonstration services and visit details.</p>
            @endif
        </section>
    @endforeach
    <input type="hidden" name="values[demo_content]" value="0">
    <label class="settings-checkbox"><input type="checkbox" name="values[demo_content]" value="1" @checked(old('values.demo_content',$values['demo_content']))> Label shop details and services as demonstration content</label>
</div>
