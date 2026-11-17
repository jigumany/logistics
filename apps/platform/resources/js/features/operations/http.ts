export async function requestJson(url: string, data?: unknown) {
    const token = document.cookie
        .split("; ")
        .find((value) => value.startsWith("XSRF-TOKEN="))
        ?.slice(11);
    const response = await fetch(url, {
        method: data === undefined ? "GET" : "POST",
        headers: {
            Accept: "application/json",
            ...(data === undefined
                ? {}
                : {
                      "Content-Type": "application/json",
                      "X-XSRF-TOKEN": decodeURIComponent(token ?? ""),
                  }),
        },
        body: data === undefined ? undefined : JSON.stringify(data),
    });
    const body = await response.json();
    if (!response.ok)
        throw new Error(
            body.errors
                ? Object.values(body.errors).flat().join(" ")
                : body.message || "The request could not be completed.",
        );
    return body;
}
