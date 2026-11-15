import { defineRailway, github, project, service, volume } from "railway/iac";
// This repository manages only its own resources in the environment. Other
// repositories export their own partial name.
// See https://docs.railway.com/infrastructure-as-code#multi-repo-projects
export const partial = "sito-laura-rossin";
export default defineRailway(() => {
  const cmsData = volume("sito-laura-rossin-volume", { region: "sfo", sizeMB: 50000 });
  const sitoLauraRossin = service("sito-laura-rossin", {
    source: github("giulianomacro-rgb/sito-laura-rossin", { branch: "main", checkSuites: false }),
    build: { buildEnvironment: "V3", builder: "DOCKERFILE" },
    healthcheck: "/healthz",
    healthcheckTimeout: 60,
    replicas: { "sfo": 1 },
    volumeMounts: { "/data": cmsData },
  });

  return project("Laura Rossin - Sito e CMS", {
    variables: { managed: false },
    resources: [sitoLauraRossin, cmsData],
  });
});
