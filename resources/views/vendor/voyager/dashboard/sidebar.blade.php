<div class="side-menu sidebar-inverse">
    <nav class="navbar navbar-default" role="navigation">
        <div class="side-menu-container">
        @php
        $user = Auth::user();
        @endphp
        @if($user->role_id == 7 || $user->role_id == 9)
            @if(isset($loggedInCompany))
                <div class="company-info text-center" style="padding: 15px;">
                    @if($loggedInCompany->logo)
                        <img src="{{ Voyager::image($loggedInCompany->logo) }}" alt="Company Logo" style="max-width: 80px; border-radius: 50%;">
                    @endif
                    <h5 style="margin-top: 10px;">{{ $loggedInCompany->name }}</h5>
                </div>
            @endif
        @else
            <div class="navbar-header">
                <a class="navbar-brand" href="{{ route('voyager.dashboard') }}">
                    <div class="logo-icon-container">
                        <?php $admin_logo_img = Voyager::setting('admin.icon_image', ''); ?>
                        @if($admin_logo_img == '')
                            <img src="{{ voyager_asset('images/logo-icon-light.png') }}" alt="Logo Icon">
                        @else
                            <img src="{{ Voyager::image($admin_logo_img) }}" alt="Logo Icon">
                        @endif
                    </div>
                    <div class="title">{{Voyager::setting('admin.title', 'VOYAGER')}}</div>
                </a>
            </div><!-- .navbar-header -->
        @endif

            <div class="panel widget center bgimage"
                 style="background-image:url({{ Voyager::image( Voyager::setting('admin.bg_image'), voyager_asset('images/bg.jpg') ) }}); background-size: cover; background-position: 0px;">
                <div class="dimmer"></div>
                <div class="panel-content">
                    <img src="{{ $user_avatar }}" class="avatar" alt="{{ Auth::user()->name }} avatar">
                    <h4>{{ ucwords(Auth::user()->name) }}</h4>
                    <p>{{ Auth::user()->email }}</p>

                    <a href="{{ route('voyager.profile') }}" class="btn btn-primary">{{ __('voyager::generic.profile') }}</a>
                    <div style="clear:both"></div>
                </div>
            </div>

        </div>
        @if(Auth::user()->role_id == 7)
            <div id="adminmenu">
                <ul class="nav navbar-nav">
                    @foreach(menu('company', '_json') as $item)
                        <li class=""><a target="_self" href="{{ $item['url'] }}"><span class="icon"></span><span class="title">{{ $item['title'] }}</span></a></li>
                    @endforeach
                </ul>
            </div>
        @elseif(Auth::user()->role_id == 11)
            <div id="adminmenu">
                <ul class="nav navbar-nav">
                    @foreach(menu('datamonk', '_json') as $item)
                        <li class=""><a target="_self" href="{{ $item['url'] }}"><span class="icon"></span><span class="title">{{ $item['title'] }}</span></a></li>
                    @endforeach
                </ul>
            </div>
        @elseif(Auth::user()->role_id == 9)
            <div id="adminmenu">
                <ul class="nav navbar-nav">
                    @foreach(menu('agents', '_json') as $item)
                        <li class=""><a target="_self" href="{{ $item['url'] }}"><span class="icon"></span><span class="title">{{ $item['title'] }}</span></a></li>
                    @endforeach
                </ul>
            </div>
        @elseif(Auth::user()->role_id == 1)
            <div id="adminmenu">
                <admin-menu :items="{{ menu('admin', '_json') }}"></admin-menu>
            </div>
        @else
            <div id="adminmenu">
                <ul class="nav navbar-nav">
                    @foreach(menu('datamonk', '_json') as $item)
                        <li class=""><a target="_self" href="{{ $item['url'] }}"><span class="icon"></span><span class="title">{{ $item['title'] }}</span></a></li>
                    @endforeach
                </ul>
            </div>
        @endif
    </nav>
</div>
