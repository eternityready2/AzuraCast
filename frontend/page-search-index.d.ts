declare module 'virtual:page-search-index' {
    // [text, routeName, tabId?, tabLabel?, inForm?]; tabId/tabLabel are '' when absent.
    const rows: ([string, string] | [string, string, string, string] | [string, string, string, string, 1])[];
    export default rows;
}
