<template>
    <div class="navdrawer-header">
        <router-link
            :to="{ name: 'admin:index'}"
            class="navbar-brand px-0"
        >
            {{ $gettext('Administration') }}
        </router-link>
    </div>

    <div class="offcanvas-body">
        <sidebar-menu
            :menu="menuItems"
            :search-sources="[adminSearch]"
            :data-sources="dataSources"
        />
    </div>
</template>

<script setup lang="ts">
import SidebarMenu from "~/components/Common/SidebarMenu.vue";
import {useAdminMenu, useAdminSearchSource} from "~/components/Admin/menu";
import {useAdminSearchSources} from "~/components/Admin/searchSources.ts";

const menuItems = useAdminMenu();
const adminSearch = useAdminSearchSource();
const dataSources = useAdminSearchSources();
</script>
