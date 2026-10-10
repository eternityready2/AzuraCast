<template>
    <licensing-report
        kind="ascap"
        :title="$gettext('ASCAP Music Use Report')"
        :subtitle="$gettext('The songs you streamed, for the songwriter side of music licensing')"
        :about-title="$gettext('About ASCAP Reports')"
        :description="$gettext('ASCAP collects for the people who wrote a song and their publishers. This report lists the music you streamed in the layout of ASCAP\'s Music Use Report for radio-style (non-interactive) services: one line per song per day with the title, writers, artist, ISRC, ISWC, date, length and number of performances.')"
        :notes="notes"
        :button-label="$gettext('Download ASCAP Report')"
        :generate-subtitle="$gettext('Choose the reporting period, usually a calendar quarter, and how the file is written.')"
        :format-options="formats"
        :count-options="counts"
        :show-header-option="true"
    />
</template>

<script setup lang="ts">
import LicensingReport from "~/components/Stations/Reports/LicensingReport.vue";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();

const formats = [
    {
        value: "tab",
        label: $gettext("Tab-delimited"),
        help: $gettext("Tab-separated .txt file"),
    },
    {
        value: "pipe",
        label: $gettext("Pipe-delimited"),
        help: $gettext("A | between columns, .txt file"),
    },
];

const counts = [
    {
        value: "listeners",
        label: $gettext("Listeners reached"),
        help: $gettext("A song heard by 12 listeners counts 12"),
    },
    {
        value: "plays",
        label: $gettext("Times played"),
        help: $gettext("Each play counts 1, whoever was listening"),
    },
];

const notes = [
    $gettext("ASCAP is separate from SoundExchange. SoundExchange pays the artists and record labels; ASCAP pays the songwriters and publishers. Streaming a song can owe both."),
    $gettext("Many ASCAP licences do not ask for this report. Schedule A of your licence agreement says whether you owe one, and ASCAP emails the template to use. If their template differs from this file, theirs is the one to follow."),
    $gettext("Only files typed Music are listed. Shows, station IDs, promos and AI DJ breaks are left out."),
    $gettext("Writers and ISWC come from each song's Writers and ISWC fields. Fill them in with Find Song Info Online on the Music Files page, or type them in a song's edit box under Custom Fields. A song with no writer saved has that column empty."),
    $gettext("Ask ASCAP which count they want for Number of Performances. Counting listeners reached is how the SoundExchange report counts; a song nobody was listening to is then left out."),
    $gettext("Dates are written MMDDYYYY and lengths MMSS, in your station time zone, as the ASCAP layout asks."),
    $gettext("Service URL is taken from the Web Site URL in your station profile. If that is empty, the column is empty."),
];
</script>
