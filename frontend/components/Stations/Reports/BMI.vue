<template>
    <licensing-report
        kind="bmi"
        :title="$gettext('BMI Music Use Report')"
        :subtitle="$gettext('The songs you streamed and your streaming hours, for the songwriter side of music licensing')"
        :about-title="$gettext('About BMI Reports')"
        :description="$gettext('BMI collects for the people who wrote a song and their publishers. This page gives you the two things a BMI web licence deals in: your streaming hours for the period, and a list of the songs you streamed with the details BMI\'s licence names for each one.')"
        :notes="notes"
        :buttons="buttons"
        :generate-title="$gettext('Generate Reports')"
        :generate-subtitle="$gettext('Choose the reporting period, then download the file you need.')"
        :format-options="formats"
    />
</template>

<script setup lang="ts">
import LicensingReport from "~/components/Stations/Reports/LicensingReport.vue";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();

const buttons = [
    {
        label: $gettext("Download Music Use Report"),
    },
    {
        label: $gettext("Download Streaming Hours"),
        param: "hours",
    },
];

const formats = [
    {
        value: "csv",
        label: $gettext("CSV"),
        help: $gettext("Comma-separated .csv file, opens in Excel"),
    },
    {
        value: "txt",
        label: $gettext("Tab-delimited"),
        help: $gettext("Tab-separated .txt file"),
    },
];

const notes = [
    $gettext("BMI is separate from SoundExchange. SoundExchange pays the artists and record labels; BMI pays the songwriters and publishers. Streaming a song can owe both."),
    $gettext("BMI's web licence asks for a yearly Financial and Streaming Hours report. Streaming Hours gives you the hours figure for the dates you pick; the financial figures come from your own books."),
    $gettext("The song-by-song Music Use Report is optional under BMI's current web licence. BMI does not publish a fixed file layout for it, so this file carries the details its licence names: title, writer, artist, record label, identifiers, length, type of use and times transmitted. If BMI sends you its own template, copy the columns across."),
    $gettext("Only files typed Music are listed. Shows, station IDs, promos and AI DJ breaks are left out."),
    $gettext("Writers, record label and ISWC come from each song's own fields. Fill them in with Find Song Info Online on the Music Files page, or type them in a song's edit box under Custom Fields."),
    $gettext("Times Transmitted is how often the song was played. Listener Performances adds up the listeners who heard each play. Streaming hours need listener analytics to be switched on."),
];
</script>
